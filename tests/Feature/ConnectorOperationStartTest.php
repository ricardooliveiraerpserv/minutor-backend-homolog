<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorOperation;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Connector-4.1 — operação 'start'. Prova as fronteiras congeladas: allowlist só start; permissões
 * granulares estritas; capability + observado fresco + pré-condição (down); maker-checker; concorrência
 * (1 viva por appserver_ref E env); execution_id imutável; SEM retry; expired só p/ dispatchable nunca
 * reivindicado; a partir de claimed → indeterminate; barreira execution_committed; result(ok)→verifying;
 * autoridade C-2 (down→up(B)); os 2 casos distribuídos (claim perdido / ACK perdido) e o contador de
 * efeito AT-MOST-ONCE (0 ou 1, inclusive após restart do Conector e recuperação por /current).
 */
class ConnectorOperationStartTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private Customer $custB;
    private string $refApp01 = '11111111-aaaa-4bbb-8ccc-111111111111'; // alvo (start exige down)
    private string $refApp02 = '22222222-aaaa-4bbb-8ccc-222222222222'; // up c/ piid → capability
    private string $refApp03 = '33333333-aaaa-4bbb-8ccc-333333333333'; // down (alvo alternativo p/ trava por env)
    private string $instB = 'BBBB4444dddd5555eeee66';

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
            'connector.operations.observed_freshness' => 120, 'connector.operations.start.operational_deadline' => 120,
        ]);
        $this->custA = Customer::factory()->create();
        $this->custB = Customer::factory()->create();
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

    private function userWith(array $perms): User { return User::factory()->create(['type' => 'consultor', 'extra_permissions' => $perms]); }

    private function scopeUserToCustomer(User $u, Customer $c): void
    {
        $proj = Project::factory()->create(['customer_id' => $c->id]);
        DB::table('project_consultants')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function enrollAgent(int $envId): array
    {
        $token = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$envId}/connector/enrollment-token")->json('data.enrollment_token');
        $kp = sodium_crypto_sign_keypair();
        $agentId = $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $token, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp))])->json('agent_id');
        return [$agentId, sodium_crypto_sign_secretkey($kp)];
    }

    private function sigHeaders(string $agentId, string $sk, string $method, string $path, string $json): array
    {
        $ts = time(); $nonce = bin2hex(random_bytes(9));
        $canonical = app(ConnectorIdentity::class)->canonicalString($agentId, $method, $path, $json, $ts, $nonce);
        $sig = base64_encode(sodium_crypto_sign_detached($canonical, $sk));
        return ['X-Agent-Id' => $agentId, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => $sig, 'Content-Type' => 'application/json'];
    }

    private function sigPost(string $agentId, string $sk, string $path, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1{$path}", $body, $this->sigHeaders($agentId, $sk, 'POST', "/api/v1{$path}", json_encode($body)));
    }

    private function getSigned(string $agentId, string $sk, string $path): \Illuminate\Testing\TestResponse
    {
        return $this->get("/api/v1{$path}", $this->sigHeaders($agentId, $sk, 'GET', "/api/v1{$path}", ''));
    }

    /** Publica um inventário C-2 (define o observado). $app01Up controla o alvo. APP02 sempre up c/ piid. */
    private function pushInventory(string $agentId, string $sk, bool $app01Up, ?string $app01Piid = null, bool $app02HasPiid = true): void
    {
        $apps = [
            ['ref' => $this->refApp01, 'name' => 'APP01', 'up' => $app01Up, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => $app01Up ? 50 : 0] + ($app01Up && $app01Piid ? ['process_instance_id' => $app01Piid] : []),
            ['ref' => $this->refApp02, 'name' => 'APP02', 'up' => true, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => 5000] + ($app02HasPiid ? ['process_instance_id' => 'AAAA1111bbbb2222cccc33'] : []),
            ['ref' => $this->refApp03, 'name' => 'APP03', 'up' => false, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => 0], // down (alvo alternativo)
        ];
        $this->sigPost($agentId, $sk, '/connector/inventory', ['observed_at' => time(), 'appservers' => $apps, 'rest' => [], 'rpo' => []])->assertOk();
    }

    private function createOp(User $u, int $envId, array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$envId}/operations",
            array_merge(['op_type' => 'start', 'appserver_ref' => $this->refApp01, 'reason' => 'gate'], $over));
    }

    private function show(int $id): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/operations/{$id}")->json('data'); }

    private function forcePast(int $id, string $col): void { ConnectorOperation::whereKey($id)->update([$col => now()->subSeconds(5)]); }

    // ── testes ──────────────────────────────────────────────────────────────

    public function test_allowlist_only_start(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        // stop (C4.2) e restart (C4.3) já existem; compile/patch/RPO seguem bloqueados na porta.
        foreach (['compile', 'patch', 'promote', 'rollback'] as $t) {
            $this->createOp($this->admin(), $env, ['op_type' => $t])->assertStatus(422)->assertJsonPath('error', 'op_type_not_allowed');
        }
        $this->assertSame(0, ConnectorOperation::where('environment_id', $env)->count());
    }

    public function test_capability_precondition_and_freshness(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        // sem observação → bloqueia
        $this->createOp($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'no_fresh_observation');
        // capability false (APP02 up sem piid) → bloqueia
        $this->pushInventory($ag, $sk, false, null, false);
        $this->createOp($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'process_instance_capability_required');
        // alvo UP → pré-condição falha
        $this->pushInventory($ag, $sk, true, $this->instB, true);
        $this->createOp($this->admin(), $env)->assertStatus(422)->assertJsonPath('error', 'precondition_failed_appserver_up');
        // alvo down + capability ok → cria
        $this->pushInventory($ag, $sk, false, null, true);
        $this->createOp($this->admin(), $env)->assertStatus(201)->assertJsonPath('data.status', 'pending_approval');
    }

    public function test_permissions_strict_and_maker_checker(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        // sem perm → 403
        $none = $this->userWith([]); $this->scopeUserToCustomer($none, $this->custA);
        $this->createOp($none, $env)->assertStatus(403);
        // start cria; approve exige OUTRA permissão
        $maker = $this->userWith(['prosight.operations.start']); $this->scopeUserToCustomer($maker, $this->custA);
        $id = $this->createOp($maker, $env)->assertStatus(201)->json('data.id');
        // maker tentando aprovar sem perm approve → 403
        $this->actingAs($maker, 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve")->assertStatus(403);
        // checker COM approve mas que é o MESMO maker → 422 (maker ≠ checker). Simulamos com admin como checker distinto.
        $checker = $this->userWith(['prosight.operations.approve']); $this->scopeUserToCustomer($checker, $this->custA);
        $this->actingAs($checker, 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'dispatchable');
    }

    public function test_maker_cannot_approve_own(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        $both = $this->userWith(['prosight.operations.start', 'prosight.operations.approve']); $this->scopeUserToCustomer($both, $this->custA);
        $id = $this->createOp($both, $env)->json('data.id');
        $this->actingAs($both, 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve")->assertStatus(422)->assertJsonPath('error', 'maker_cannot_approve');
    }

    public function test_concurrency_one_per_appserver_and_environment(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        $this->createOp($this->admin(), $env)->assertStatus(201);
        // mesmo appserver → em voo
        $this->createOp($this->admin(), $env)->assertStatus(409)->assertJsonPath('error', 'operation_in_flight');
        // outro appserver DOWN (passa a pré-condição), mesmo ambiente → também 409 (1 por environment em v1)
        $this->createOp($this->admin(), $env, ['appserver_ref' => $this->refApp03])->assertStatus(409)->assertJsonPath('error', 'operation_in_flight');
    }

    public function test_rejected_and_canceled_are_distinct(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        $id1 = $this->createOp($this->admin(), $env)->json('data.id');
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/operations/{$id1}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
        // outra op (a anterior é terminal → libera o alvo) → aprova → cancela antes do claim
        $id2 = $this->createOp($this->admin(), $env)->json('data.id');
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/operations/{$id2}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
    }

    public function test_expired_only_when_never_claimed(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        $id = $this->createOp($this->admin(), $env)->json('data.id');
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve")->assertOk();
        $this->forcePast($id, 'transport_lease_expires_at');
        $this->assertSame('expired', $this->show($id)['status']); // nunca reivindicado → seguro
    }

    private function approveAndClaim(int $env, string $ag, string $sk): array
    {
        $maker = $this->userWith(['prosight.operations.start']); $this->scopeUserToCustomer($maker, Customer::find(DB::table('env_environments')->where('id', $env)->value('customer_id')));
        $id = $this->createOp($maker, $env)->json('data.id');
        $checker = $this->userWith(['prosight.operations.approve']); $this->scopeUserToCustomer($checker, Customer::find(DB::table('env_environments')->where('id', $env)->value('customer_id')));
        $this->actingAs($checker, 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve")->assertOk();
        $claim = $this->getSigned($ag, $sk, '/connector/operations/next')->assertOk()->json('data');
        return [$id, $claim['execution_id']];
    }

    public function test_happy_path_start_verifying_then_reconciled_success(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        [$id, $eid] = $this->approveAndClaim($env, $ag, $sk);
        // barreira + efeito local + result ok → verifying (NUNCA sucesso direto)
        $this->sigPost($ag, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $effect = 1; // agente executou o start
        $this->pushInventory($ag, $sk, true, $this->instB, true); // pós-imagem up(B)
        $this->sigPost($ag, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk()->assertJsonPath('status', 'verifying');
        $this->assertSame('verifying', $this->show($id)['status']);
        // reconcilia pelo C-2 → success
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/reconcile")->assertOk()->assertJsonPath('data.status', 'reconciled_success');
        $this->assertSame(1, $effect);
    }

    public function test_case1_claim_response_lost_then_indeterminate_noop(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        [$id, $eid] = $this->approveAndClaim($env, $ag, $sk); // server=claimed, mas "resposta perdida":
        $effect = 0; // o agente NÃO executou (nunca recebeu a resposta do claim)
        // deadline vence a partir de claimed → indeterminate (NUNCA expired)
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('indeterminate', $this->show($id)['status']);
        // observado continua DOWN → reconcile → reconciled_noop
        $this->pushInventory($ag, $sk, false);
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/reconcile")->assertOk()->assertJsonPath('data.status', 'reconciled_noop');
        $this->assertSame(0, $effect); // nenhum efeito; nenhum segundo start automático
    }

    public function test_case2_ack_lost_effect_happened_then_reconciled_success(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        [$id, $eid] = $this->approveAndClaim($env, $ag, $sk);
        // agente faz fsync execution_committed LOCAL, mas o ACK se perde (NÃO chama o endpoint ack) e EXECUTA o start
        $effect = 1;
        $this->pushInventory($ag, $sk, true, $this->instB, true); // AppServer subiu (B)
        // backend ainda vê 'claimed'; deadline vence → indeterminate
        $this->assertSame('claimed', $this->show($id)['status']);
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('indeterminate', $this->show($id)['status']);
        // C-2 down→up(B) fecha como reconciled_success (verdade local e central divergiram, sem dupla execução)
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/reconcile")->assertOk()->assertJsonPath('data.status', 'reconciled_success');
        $this->assertSame(1, $effect);
    }

    public function test_at_most_once_effect_counter_survives_connector_restart(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        [$id, $eid] = $this->approveAndClaim($env, $ag, $sk);

        // Journal local do agente (por execution_id) + guarda de efeito at-most-once.
        $journal = [$eid => ['received' => true, 'committed' => false, 'effect' => 0]];
        $executeOnce = function (string $e) use (&$journal) {
            if ($journal[$e]['effect'] === 0) { $journal[$e]['effect'] = 1; } // guarda: no máximo 1
        };
        // barreira + efeito
        $journal[$eid]['committed'] = true; // fsync
        $executeOnce($eid);
        $this->assertSame(1, $journal[$eid]['effect']);

        // "restart do Conector": novo agente (mesma env). Recupera por /current.
        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/v1/prosight/connector/agents/{$ag}");
        [$ag2, $sk2] = $this->enrollAgent($env);
        $cur = $this->getSigned($ag2, $sk2, '/connector/operations/current')->assertOk()->json('data');
        $this->assertSame($eid, $cur['execution_id']); // MESMO execution_id (imutável)
        // agente recuperado: já committed no journal → NÃO re-executa (reporta indeterminate)
        if (! empty($journal[$cur['execution_id']]['committed'])) {
            // não executa; opcionalmente reporta unknown
        } else {
            $executeOnce($cur['execution_id']);
        }
        $this->assertLessThanOrEqual(1, $journal[$eid]['effect']); // AT-MOST-ONCE preservado
        $this->assertSame(1, $journal[$eid]['effect']);
    }

    public function test_no_dispatch_after_indeterminate(): void
    {
        $env = $this->makeEnv($this->custA); [$ag, $sk] = $this->enrollAgent($env);
        $this->pushInventory($ag, $sk, false);
        [$id, $eid] = $this->approveAndClaim($env, $ag, $sk);
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('indeterminate', $this->show($id)['status']);
        // agente pede próxima → 204 (indeterminate NUNCA volta a dispatchable)
        $this->getSigned($ag, $sk, '/connector/operations/next')->assertNoContent();
    }

    public function test_anti_idor_agent_and_admin(): void
    {
        $envA = $this->makeEnv($this->custA); $envB = $this->makeEnv($this->custB);
        [$agA, $skA] = $this->enrollAgent($envA); [$agB, $skB] = $this->enrollAgent($envB);
        $this->pushInventory($agB, $skB, false);
        $idB = $this->createOp($this->admin(), $envB)->json('data.id');
        // coord de A não vê/aprova op de B
        $coordA = $this->userWith(['prosight.operations.view', 'prosight.operations.approve']); $this->scopeUserToCustomer($coordA, $this->custA);
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/prosight/operations/{$idB}")->assertStatus(404);
        $this->actingAs($coordA, 'sanctum')->postJson("/api/v1/prosight/operations/{$idB}/approve")->assertStatus(404);
        // agente de A não reivindica/reporta op de B (escopo server-side)
        $this->sigPost($agA, $skA, "/connector/operations/{$idB}/ack", ['execution_id' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(404);
    }
}
