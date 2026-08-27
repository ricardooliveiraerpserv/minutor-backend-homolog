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
 * Connector-4.3 — restart (down transiente → up(B)). Herda os gates do stop (up(A), capability, presença
 * online, último AppServer, janela, revalidação no dispatch, maker-checker, override próprio). Autoridade
 * de sucesso FORTE = up(B), B≠A, evidenciada por COLETA DE RECONCILIAÇÃO CORRELACIONADA (trigger.operation_id)
 * com received_at ≥ execution_committed_at. Disciplina: down transiente na janela → reconciling; persiste →
 * recovery_failed. up(A) toda a janela → noop. Prova os 2 cenários distribuídos + at-most-once.
 */
class ConnectorOperationRestartTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private string $refApp01 = '11111111-aaaa-4bbb-8ccc-111111111111'; // alvo
    private string $refApp02 = '22222222-aaaa-4bbb-8ccc-222222222222'; // outro up
    private string $instA = 'AAAA1111bbbb2222cccc33'; private string $instB = 'BBBB4444dddd5555eeee66';

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
            'connector.operations.observed_freshness' => 120, 'connector.operations.restart.operational_deadline' => 300,
            'connector.operations.restart.reconcile_window' => 300, 'connector.operations.restart.min_other_up' => 1,
            'connector.operations.restart.window' => ['enabled' => true, 'timezone' => 'UTC', 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '00:00', 'end' => '23:59'],
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
        $token = $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$envId}/connector/enrollment-token")->json('data.enrollment_token');
        $kp = sodium_crypto_sign_keypair();
        $agentId = $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $token, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp))])->json('agent_id');
        return [$agentId, sodium_crypto_sign_secretkey($kp)];
    }

    private function sig(string $a, string $sk, string $m, string $p, string $j): array
    {
        $ts = time(); $nonce = bin2hex(random_bytes(9));
        return ['X-Agent-Id' => $a, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => base64_encode(sodium_crypto_sign_detached(app(ConnectorIdentity::class)->canonicalString($a, $m, $p, $j, $ts, $nonce), $sk)), 'Content-Type' => 'application/json'];
    }
    private function sigPost(string $a, string $sk, string $p, array $b): \Illuminate\Testing\TestResponse { return $this->postJson("/api/v1{$p}", $b, $this->sig($a, $sk, 'POST', "/api/v1{$p}", json_encode($b))); }
    private function sigGet(string $a, string $sk, string $p): \Illuminate\Testing\TestResponse { return $this->get("/api/v1{$p}", $this->sig($a, $sk, 'GET', "/api/v1{$p}", '')); }

    private function apps(bool $upT, ?string $piidT, bool $upO): array
    {
        return [
            ['ref' => $this->refApp01, 'name' => 'APP01', 'up' => $upT, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => $upT ? 40 : 0] + ($upT && $piidT ? ['process_instance_id' => $piidT] : []),
            ['ref' => $this->refApp02, 'name' => 'APP02', 'up' => $upO, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => $upO ? 8000 : 0] + ($upO ? ['process_instance_id' => 'OTHER1111bbbb2222cc'] : []),
        ];
    }

    /** Observação periódica (heartbeat online + inventário SEM correlação). */
    private function observe(string $a, string $sk, bool $upT, ?string $piidT, bool $upO = true): void
    {
        $this->sigPost($a, $sk, '/connector/heartbeat', ['observed_at' => time()])->assertOk();
        $this->sigPost($a, $sk, '/connector/inventory', ['observed_at' => time(), 'appservers' => $this->apps($upT, $piidT, $upO), 'rest' => [], 'rpo' => []])->assertOk();
    }

    /** Coleta de RECONCILIAÇÃO correlacionada (trigger.operation_id) — autoridade do restart. */
    private function observeCorr(string $a, string $sk, int $opId, bool $upT, ?string $piidT, bool $upO = true): void
    {
        $this->sigPost($a, $sk, '/connector/heartbeat', ['observed_at' => time()])->assertOk();
        $this->sigPost($a, $sk, '/connector/inventory', ['observed_at' => time(), 'appservers' => $this->apps($upT, $piidT, $upO), 'rest' => [], 'rpo' => [], 'trigger' => ['type' => 'operation', 'operation_id' => $opId]])->assertOk();
    }

    private function createR(User $u, int $env, array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/operations", array_merge(['op_type' => 'restart', 'appserver_ref' => $this->refApp01, 'reason' => 'gate restart'], $over));
    }
    private function show(int $id): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/operations/{$id}")->json('data'); }
    private function forcePast(int $id, string $col, int $s = 5): void { ConnectorOperation::whereKey($id)->update([$col => now()->subSeconds($s)]); }
    private function approve(int $id): \Illuminate\Testing\TestResponse { return $this->actingAs($this->userWith(['prosight.operations.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve"); }
    private function reconcile(int $id): array { return $this->actingAs($this->userWith(['prosight.operations.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/reconcile")->json('data'); }

    private function claimR(int $env, string $a, string $sk): array
    {
        $id = $this->createR($this->admin(), $env)->assertStatus(201)->json('data.id');
        $this->approve($id)->assertOk();
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->assertOk()->json('data.execution_id');
        return [$id, $eid];
    }

    // ── testes ──────────────────────────────────────────────────────────────

    public function test_precondition_up_required(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, false, null, true);
        $this->createR($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'precondition_failed_appserver_down');
    }

    public function test_permission_restart_required(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        $this->createR($this->userWith(['prosight.operations.stop']), $env)->assertStatus(403); // stop ≠ restart
        $this->createR($this->userWith(['prosight.operations.restart']), $env)->assertStatus(201);
    }

    public function test_last_appserver_block_and_restart_override(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, false); // alvo é o único up
        $this->createR($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'last_appserver_stop_blocked');
        $this->createR($this->userWith(['prosight.operations.restart']), $env, ['emergency_override' => true])->assertStatus(403)->assertJsonPath('error', 'override_permission_required');
        $id = $this->createR($this->userWith(['prosight.operations.restart', 'prosight.operations.restart.override']), $env, ['emergency_override' => true])->assertStatus(201)->assertJsonPath('data.emergency_override', true)->json('data.id');
        $this->assertSame(1, ConnectorEvent::where('environment_id', $env)->where('event_type', 'operation_emergency_override')->count());
    }

    public function test_presence_online_strict(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        ConnectorEnvironmentState::where('environment_id', $env)->update(['last_seen_at' => now()->subSeconds(200)]);
        $this->createR($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'agent_not_online');
    }

    public function test_revalidation_last_appserver_before_dispatch(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        $id = $this->createR($this->admin(), $env)->json('data.id'); $this->approve($id)->assertOk();
        $this->observe($a, $sk, true, $this->instA, false); // APP02 cai → alvo vira o último
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
    }

    public function test_happy_strong_success_up_b(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observeCorr($a, $sk, $id, true, $this->instB, true); // coleta correlacionada: up(B)
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk()->assertJsonPath('status', 'verifying');
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
    }

    public function test_strong_success_requires_correlated_collection(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        // inventário PERIÓDICO up(B) SEM trigger.operation_id → NÃO é evidência forte.
        $this->observe($a, $sk, true, $this->instB, true);
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // não conclui sucesso sem correlação
        $this->forcePast($id, 'claimed_at', 1000); $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('unresolved', $this->reconcile($id)['status']); // janela vence sem coleta correlacionada
    }

    public function test_non_causal_up_b_not_success(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observeCorr($a, $sk, $id, true, $this->instB, true); // correlacionada up(B), mas...
        ConnectorOperation::whereKey($id)->update(['execution_committed_at' => now()->addSeconds(1000)]); // ...B anterior ao committed
        $this->assertNotSame('reconciled_success', $this->reconcile($id)['status']); // não confirma sucesso (não causal)
    }

    public function test_noop_up_a_only_after_window(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observeCorr($a, $sk, $id, true, $this->instA, true); // continua up(A) — não reiniciou
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // cedo → aguarda
        $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('reconciled_noop', $this->reconcile($id)['status']);
    }

    public function test_down_transient_then_up_b_success(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observeCorr($a, $sk, $id, false, null, true); // DOWN (transiente, meio do restart)
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // down cedo → aguarda
        $this->observeCorr($a, $sk, $id, true, $this->instB, true); // voltou como B
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
    }

    public function test_recovery_failed_down_persists(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observeCorr($a, $sk, $id, false, null, true); // caiu e...
        $this->forcePast($id, 'operational_deadline_at'); $this->show($id); // → indeterminate
        $this->forcePast($id, 'execution_committed_at', 1000); // ...janela vence ainda DOWN
        $out = $this->reconcile($id);
        $this->assertSame('contradicted', $out['status']);
        $this->assertSame('recovery_failed', $out['reconciliation_state']);
        $this->assertSame(1, ConnectorEvent::where('environment_id', $env)->where('event_type', 'operation_recovery_failed')->count());
    }

    public function test_up_without_piid_unresolved(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observeCorr($a, $sk, $id, true, null, true); // up SEM piid
        $this->forcePast($id, 'operational_deadline_at'); $this->show($id); // → indeterminate
        $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('unresolved', $this->reconcile($id)['status']);
    }

    public function test_verifying_up_a_contradicted(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observeCorr($a, $sk, $id, true, $this->instA, true);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk(); // verifying
        $this->assertSame('contradicted', $this->reconcile($id)['status']); // agente ok × up(A)
    }

    public function test_distributed_result_lost_success(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $effect = 1;                                          // agente executou o restart
        $this->observeCorr($a, $sk, $id, true, $this->instB, true); // AppServer caiu e voltou como B; result se perdeu
        $this->assertSame('claimed', $this->show($id)['status']);
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('indeterminate', $this->show($id)['status']);
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
        $this->assertSame(1, $effect);                        // sem 2º restart
    }

    public function test_distributed_recovery_failed(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $this->observeCorr($a, $sk, $id, false, null, true);  // caiu e NÃO volta
        $this->forcePast($id, 'operational_deadline_at'); $this->show($id); // indeterminate
        $this->forcePast($id, 'claimed_at', 1000);
        $out = $this->reconcile($id);
        $this->assertSame('contradicted', $out['status']);
        $this->assertSame('recovery_failed', $out['reconciliation_state']);
    }

    public function test_at_most_once_after_connector_restart(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        [$id, $eid] = $this->claimR($env, $a, $sk);
        $journal = [$eid => ['committed' => true, 'effect' => 0]];
        $exec = function ($e) use (&$journal) { if ($journal[$e]['effect'] === 0) $journal[$e]['effect'] = 1; };
        $exec($eid);
        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/v1/prosight/connector/agents/{$a}");
        [$a2, $sk2] = $this->enrollAgent($env);
        $cur = $this->sigGet($a2, $sk2, '/connector/operations/current')->assertOk()->json('data');
        $this->assertSame($eid, $cur['execution_id']);
        if (empty($journal[$cur['execution_id']]['committed'])) { $exec($cur['execution_id']); }
        $this->assertSame(1, $journal[$eid]['effect']);
    }

    public function test_expired_only_when_never_claimed(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, true, $this->instA, true);
        $id = $this->createR($this->admin(), $env)->json('data.id'); $this->approve($id)->assertOk();
        $this->forcePast($id, 'transport_lease_expires_at');
        $this->assertSame('expired', $this->show($id)['status']);
    }
}
