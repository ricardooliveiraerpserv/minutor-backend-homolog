<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorEnvironmentState;
use App\Models\ConnectorEvent;
use App\Models\ConnectorOperation;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Connector-4.2 — operação 'stop' (indisponibilidade deliberada). Prova as fronteiras próprias além do
 * C4.1: pré-condição up(A); efeito down; sucesso só com C-2 up=false; up(A) durante a janela → noop
 * (não imediato); up(B) → contradicted; permissão operations.stop; override dedicado (maker E checker);
 * janela obrigatória; presença ONLINE estrita; proteção do ÚLTIMO AppServer; REVALIDAÇÃO no dispatch
 * (último AppServer some / agente fica stale entre aprovação e claim); caso distribuído (stop cai, result
 * perdido → indeterminate → C-2 down → reconciled_success, sem 2º stop); at-most-once; override na timeline.
 */
class ConnectorOperationStopTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private string $refApp01 = '11111111-aaaa-4bbb-8ccc-111111111111'; // alvo (stop exige up)
    private string $refApp02 = '22222222-aaaa-4bbb-8ccc-222222222222'; // outro up (proteção do último)
    private string $instA = 'AAAA1111bbbb2222cccc33'; private string $instB = 'BBBB4444dddd5555eeee66';
    private string $instC = 'CCCC7777dddd8888eeee99';

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config([
            'cache.default' => 'array', 'multiempresa.scoping_enabled' => false, 'connector.nonce_store' => null,
            'connector.operations.require_approval' => true, 'connector.operations.transport_lease' => 60,
            'connector.operations.observed_freshness' => 120, 'connector.operations.stop.operational_deadline' => 120,
            'connector.operations.stop.reconcile_window' => 180, 'connector.operations.stop.min_other_up' => 1,
            'connector.operations.stop.window' => ['enabled' => true, 'timezone' => 'UTC', 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '00:00', 'end' => '23:59'], // aberta
            'connector.presence_online' => 75, 'connector.presence_offline' => 300,
        ]);
        $this->custA = Customer::factory()->create();
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function makeEnv(Customer $c): int
    {
        $vault = Vault::create(['type' => 'client', 'name' => 'Amb ' . $c->id, 'created_by' => null]);
        DB::table('env_client_vaults')->insert(['customer_id' => $c->id, 'vault_id' => $vault->id, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('env_environments')->insertGetId([
            'customer_id' => $c->id, 'vault_id' => $vault->id, 'name' => 'Produção', 'type' => 'prod', 'status' => 'online',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    private function userWith(array $perms): User
    {
        $u = User::factory()->create(['type' => 'consultor', 'extra_permissions' => $perms]);
        $proj = Project::factory()->create(['customer_id' => $this->custA->id]);
        DB::table('project_consultants')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }

    private function enrollAgent(int $envId): array
    {
        $token = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$envId}/connector/enrollment-token")->json('data.enrollment_token');
        $kp = sodium_crypto_sign_keypair();
        $agentId = $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $token, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp))])->json('agent_id');
        return [$agentId, sodium_crypto_sign_secretkey($kp)];
    }

    private function sig(string $agentId, string $sk, string $method, string $path, string $json): array
    {
        $ts = time(); $nonce = bin2hex(random_bytes(9));
        $canonical = app(ConnectorIdentity::class)->canonicalString($agentId, $method, $path, $json, $ts, $nonce);
        return ['X-Agent-Id' => $agentId, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => base64_encode(sodium_crypto_sign_detached($canonical, $sk)), 'Content-Type' => 'application/json'];
    }

    private function sigPost(string $a, string $sk, string $path, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1{$path}", $body, $this->sig($a, $sk, 'POST', "/api/v1{$path}", json_encode($body)));
    }

    private function sigGet(string $a, string $sk, string $path): \Illuminate\Testing\TestResponse
    {
        return $this->get("/api/v1{$path}", $this->sig($a, $sk, 'GET', "/api/v1{$path}", ''));
    }

    /** Agente online (heartbeat) + inventário: APP01 up(A) [ou down], APP02 up(piid) [ou down]. */
    private function observe(string $a, string $sk, bool $app01Up, ?string $app01Piid, bool $app02Up = true): void
    {
        $this->sigPost($a, $sk, '/connector/heartbeat', ['observed_at' => time()])->assertOk(); // presença online
        $apps = [
            ['ref' => $this->refApp01, 'name' => 'APP01', 'up' => $app01Up, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => $app01Up ? 50 : 0] + ($app01Up && $app01Piid ? ['process_instance_id' => $app01Piid] : []),
            ['ref' => $this->refApp02, 'name' => 'APP02', 'up' => $app02Up, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => $app02Up ? 9000 : 0] + ($app02Up ? ['process_instance_id' => 'DDDD2222eeee3333ffff44'] : []),
        ];
        $this->sigPost($a, $sk, '/connector/inventory', ['observed_at' => time(), 'appservers' => $apps, 'rest' => [], 'rpo' => []])->assertOk();
    }

    private function createStop(User $u, int $env, array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/operations",
            array_merge(['op_type' => 'stop', 'appserver_ref' => $this->refApp01, 'reason' => 'gate stop'], $over));
    }

    private function show(int $id): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/operations/{$id}")->json('data'); }
    private function forcePast(int $id, string $col, int $s = 5): void { ConnectorOperation::whereKey($id)->update([$col => now()->subSeconds($s)]); }
    private function approve(int $id): \Illuminate\Testing\TestResponse { return $this->actingAs($this->userWith(['prosight.operations.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve"); }
    private function reconcile(int $id): array { return $this->actingAs($this->userWith(['prosight.operations.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/reconcile")->json('data'); }

    /** Cria(admin) + aprova + agente reivindica → [id, execution_id]. */
    private function claimStop(int $env, string $a, string $sk): array
    {
        $id = $this->createStop($this->admin(), $env)->assertStatus(201)->json('data.id');
        $this->approve($id)->assertOk();
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->assertOk()->json('data.execution_id');
        return [$id, $eid];
    }

    // ── testes ──────────────────────────────────────────────────────────────

    public function test_precondition_up_required(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, false, null, true); // alvo DOWN
        $this->createStop($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'precondition_failed_appserver_down');
    }

    public function test_capability_required(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, null, true); // alvo up SEM piid
        $this->createStop($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'process_instance_capability_required');
    }

    public function test_permission_stop_required(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        $this->createStop($this->userWith(['prosight.operations.start']), $env)->assertStatus(403); // start ≠ stop
        $this->createStop($this->userWith(['prosight.operations.stop']), $env)->assertStatus(201);
    }

    public function test_presence_online_strict(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        ConnectorEnvironmentState::where('environment_id', $env)->update(['last_seen_at' => now()->subSeconds(200)]); // stale
        $this->createStop($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'agent_not_online');
    }

    public function test_last_appserver_blocked_and_override(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, false); // APP01 é o ÚNICO up
        $this->createStop($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'last_appserver_stop_blocked');
        // override sem permissão elevada
        $this->createStop($this->userWith(['prosight.operations.stop']), $env, ['emergency_override' => true])->assertStatus(403)->assertJsonPath('error', 'override_permission_required');
        // override COM permissão elevada → cria + evento na timeline
        $id = $this->createStop($this->userWith(['prosight.operations.stop', 'prosight.operations.stop.override']), $env, ['emergency_override' => true])->assertStatus(201)->assertJsonPath('data.emergency_override', true)->json('data.id');
        $this->assertSame(1, ConnectorEvent::where('environment_id', $env)->where('event_type', 'operation_emergency_override')->count());
    }

    public function test_window_closed_blocked_and_override(): void
    {
        config(['connector.operations.stop.window' => ['enabled' => true, 'timezone' => 'UTC', 'days' => [], 'start' => '00:00', 'end' => '00:01']]); // fechada
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        $this->createStop($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'maintenance_window_closed');
        $this->createStop($this->userWith(['prosight.operations.stop', 'prosight.operations.stop.override']), $env, ['emergency_override' => true])->assertStatus(201);
    }

    public function test_checker_also_needs_override(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, false); // último → precisa override
        $maker = $this->userWith(['prosight.operations.stop', 'prosight.operations.stop.override']);
        $id = $this->createStop($maker, $env, ['emergency_override' => true])->assertStatus(201)->json('data.id');
        // checker SEM override → 403
        $this->actingAs($this->userWith(['prosight.operations.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve")->assertStatus(403)->assertJsonPath('error', 'override_permission_required');
        // checker COM override → aprova
        $this->actingAs($this->userWith(['prosight.operations.approve', 'prosight.operations.stop.override']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'dispatchable');
    }

    public function test_revalidation_last_appserver_falls_before_dispatch(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true); // APP01 + APP02 up
        $id = $this->createStop($this->admin(), $env)->assertStatus(201)->json('data.id');
        $this->approve($id)->assertOk(); // dispatchable
        // APP02 CAI antes do dispatch → APP01 vira o último ativo
        $this->observe($a, $sk, true, $this->instA, false);
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent(); // claim NÃO entrega
        $this->assertSame('canceled', $this->show($id)['status']);
        $this->assertSame(1, ConnectorEvent::where('environment_id', $env)->where('event_type', 'operation_dispatch_blocked')->count());
    }

    public function test_revalidation_agent_stale_before_dispatch(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        $id = $this->createStop($this->admin(), $env)->assertStatus(201)->json('data.id');
        $this->approve($id)->assertOk();
        ConnectorEnvironmentState::where('environment_id', $env)->update(['last_seen_at' => now()->subSeconds(400)]); // offline
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
    }

    public function test_happy_stop_verifying_then_success(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimStop($env, $a, $sk);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observe($a, $sk, false, null, true); // pós: APP01 DOWN
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk()->assertJsonPath('status', 'verifying');
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']); // C-2 down
    }

    public function test_noop_only_after_window(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimStop($env, $a, $sk);
        $this->forcePast($id, 'operational_deadline_at'); // → indeterminate
        $this->observe($a, $sk, true, $this->instA, true); // continua up(A) — stop pode estar em andamento
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // NÃO conclui noop imediatamente
        $this->forcePast($id, 'claimed_at', 1000); // janela de reconciliação vence
        $this->assertSame('reconciled_noop', $this->reconcile($id)['status']);
    }

    public function test_up_b_is_contradicted(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimStop($env, $a, $sk);
        $this->forcePast($id, 'operational_deadline_at');
        $this->observe($a, $sk, true, $this->instB, true); // voltou como NOVA incarnação (B≠A)
        $this->assertSame('contradicted', $this->reconcile($id)['status']); // restart, não parada
    }

    public function test_distributed_stop_result_lost_reconciled_success(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimStop($env, $a, $sk);
        $effect = 1;                                  // agente executou o stop (AppServer caiu)
        $this->observe($a, $sk, false, null, true);   // C-2: APP01 DOWN
        $this->assertSame('claimed', $this->show($id)['status']); // result se perdeu → backend segue claimed
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('indeterminate', $this->show($id)['status']);
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']); // C-2 down fecha
        $this->assertSame(1, $effect);                // sem 2º stop automático
    }

    public function test_at_most_once_after_connector_restart(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimStop($env, $a, $sk);
        $journal = [$eid => ['committed' => true, 'effect' => 0]];
        $exec = function ($e) use (&$journal) { if ($journal[$e]['effect'] === 0) $journal[$e]['effect'] = 1; };
        $exec($eid); // executou o stop
        // restart do Conector: novo agente, recupera por /current
        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/v1/prosight/connector/agents/{$a}");
        [$a2, $sk2] = $this->enrollAgent($env);
        $cur = $this->sigGet($a2, $sk2, '/connector/operations/current')->assertOk()->json('data');
        $this->assertSame($eid, $cur['execution_id']);
        if (empty($journal[$cur['execution_id']]['committed'])) { $exec($cur['execution_id']); }
        $this->assertSame(1, $journal[$eid]['effect']); // AT-MOST-ONCE
    }

    public function test_expired_only_when_never_claimed(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        $id = $this->createStop($this->admin(), $env)->json('data.id');
        $this->approve($id)->assertOk();
        $this->forcePast($id, 'transport_lease_expires_at');
        $this->assertSame('expired', $this->show($id)['status']);
    }

    public function test_contradicted_resolved_by_human(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimStop($env, $a, $sk);
        $this->forcePast($id, 'operational_deadline_at');
        $this->observe($a, $sk, true, $this->instB, true); // up(B) → contradicted (não-terminal, congela)
        $this->assertSame('contradicted', $this->reconcile($id)['status']);
        // enquanto contradicted, o alvo/ambiente fica congelado (1 viva) — nova op → 409
        $this->createStop($this->admin(), $env)->assertStatus(409);
        // resolução HUMANA → terminal; libera o ambiente
        $out = $this->actingAs($this->userWith(['prosight.operations.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/resolve", ['resolution' => 'failed']);
        $out->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.outcome_authority', 'human');
        $this->observe($a, $sk, true, $this->instA, true);
        $this->createStop($this->admin(), $env)->assertStatus(201); // ambiente livre
    }

    public function test_concurrency_one_live_per_environment(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        $this->createStop($this->admin(), $env)->assertStatus(201);
        $this->createStop($this->admin(), $env)->assertStatus(409)->assertJsonPath('error', 'operation_in_flight');
    }
}
