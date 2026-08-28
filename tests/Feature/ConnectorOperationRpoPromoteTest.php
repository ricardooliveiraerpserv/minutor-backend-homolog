<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorEnvironmentState;
use App\Models\ConnectorEvent;
use App\Models\ConnectorOperation;
use App\Models\Customer;
use App\Models\Project;
use App\Models\RpoArtifact;
use App\Models\RpoTarget;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Connector-5.2 — rpo_promote (SÓ activation_mode=hot). Publicação GOVERNADA de RPO: artefato ≠ ativação;
 * barreira imediatamente antes do apply; pin dos bytes validados (agente); dupla validação from_hash
 * (central+local); unidade física ÚNICA (publish_unit_id); reconciliação do TARGET INTEIRO por coleta
 * correlacionada; N-of-M; DOIS marcadores de journal (execution_committed × effect_started); SEM C4 interno;
 * SEM rollback automático. hot NÃO tem last-AppServer/janela (sem outage). Sucesso técnico → last_successfully_published
 * (≠ known_good). Prova a matriz de gates + os 2 cenários distribuídos + at-most-once. ZERO bytes/path.
 */
class ConnectorOperationRpoPromoteTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private string $refApp01 = '11111111-aaaa-4bbb-8ccc-111111111111';
    private string $refApp02 = '22222222-aaaa-4bbb-8ccc-222222222222';
    private string $hA; private string $hB; private string $hX;

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
            'connector.operations.observed_freshness' => 120,
            'connector.operations.rpo_promote.operational_deadline' => 180,
            'connector.operations.rpo_promote.reconcile_window' => 300,
            'connector.operations.rpo.executable_activation_modes' => ['hot'],
            'connector.operations.rpo.required_approvals' => ['prod' => 1, 'default' => 1], // N-of-M testado à parte
            'connector.operations.rpo.supported_capabilities' => [['name' => 'rpo_publish', 'contract_version' => 1]],
            'connector.presence_online' => 75, 'connector.presence_offline' => 300,
        ]);
        $this->custA = Customer::factory()->create();
        $this->hA = str_repeat('a', 64); $this->hB = str_repeat('b', 64); $this->hX = str_repeat('c', 64);
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

    /** AppServers do target (default ambos up). */
    private function appservers(array $upByRef, array $refs): array
    {
        $names = [$this->refApp01 => 'APP01', $this->refApp02 => 'APP02'];
        $out = [];
        foreach ($refs as $ref) {
            $up = $upByRef[$ref] ?? true;
            $out[] = ['ref' => $ref, 'name' => $names[$ref], 'up' => $up, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => $up ? 50 : 0] + ($up ? ['process_instance_id' => 'PI' . substr(md5($ref), 0, 18)] : []);
        }
        return $out;
    }

    private function rpoEntries(array $hashByRef, array $puByRef, array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            $h = $hashByRef[$ref] ?? null;
            if ($h === null) { continue; }
            $out[] = ['appserver_ref' => $ref, 'hash' => $h, 'version' => 'TTTP', 'publish_unit_id' => $puByRef[$ref] ?? 'U1'];
        }
        return $out;
    }

    /**
     * Observação. $opts: refs, up[], pu[], opId (correlacionada), cver (contract_version), mode, withCap.
     * hashByRef default: hA em todos os refs.
     */
    private function observe(string $a, string $sk, array $hashByRef = [], array $opts = []): void
    {
        $refs = $opts['refs'] ?? [$this->refApp01, $this->refApp02];
        $hashByRef = $hashByRef ?: array_fill_keys($refs, $this->hA);
        $this->sigPost($a, $sk, '/connector/heartbeat', ['observed_at' => time()])->assertOk();
        $body = ['observed_at' => time(), 'appservers' => $this->appservers($opts['up'] ?? [], $refs), 'rest' => [], 'rpo' => $this->rpoEntries($hashByRef, $opts['pu'] ?? [], $refs)];
        if (($opts['withCap'] ?? true)) {
            $body['capabilities'] = [['name' => 'rpo_publish', 'adapter' => 'totvs_x', 'contract_version' => $opts['cver'] ?? 1, 'operations' => ['promote'], 'activation_mode' => $opts['mode'] ?? 'hot']];
        }
        if (! empty($opts['opId'])) { $body['trigger'] = ['type' => 'operation', 'operation_id' => $opts['opId']]; }
        $this->sigPost($a, $sk, '/connector/inventory', $body)->assertOk();
    }

    private function register(int $env, string $hash, array $compat = ['appserver_versions' => ['12.1.2410']]): int
    {
        return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/artifacts/register", ['hash' => $hash, 'provenance' => 'GMUD', 'compatibility' => $compat, 'version' => 'TTTP'])->json('data.id');
    }
    private function createTarget(int $env, array $refs): int
    {
        return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/targets", ['name' => 'fin', 'appserver_refs' => $refs])->json('data.id');
    }
    private function confirmTarget(int $tid): \Illuminate\Testing\TestResponse { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/confirm"); }
    private function targetView(int $tid): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/rpo/targets/{$tid}")->json('data'); }

    private function promote(User $u, int $tid, int $toId, string $reason = 'promote hot'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/promote", ['to_artifact_id' => $toId, 'reason' => $reason]);
    }
    private function approve(int $id): \Illuminate\Testing\TestResponse { return $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve"); }
    private function approveAs(int $id, User $u): \Illuminate\Testing\TestResponse { return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve"); }
    private function reconcile(int $id): array { return $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/reconcile")->json('data'); }
    private function resolve(int $id, string $r): \Illuminate\Testing\TestResponse { return $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/resolve", ['resolution' => $r]); }
    private function show(int $id): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/operations/{$id}")->json('data'); }
    private function forcePast(int $id, string $col, int $s = 5): void { ConnectorOperation::whereKey($id)->update([$col => now()->subSeconds($s)]); }
    private function blockReason(int $env): ?string { return optional(ConnectorEvent::where('environment_id', $env)->where('event_type', 'operation_dispatch_blocked')->latest('id')->first())->meta['reason'] ?? null; }

    /** Setup padrão: 2 AppServers em hA, cap hot, pu U1; target [APP01,APP02] confirmado; to=hB. */
    private function setup2(int $env, string $a, string $sk): array
    {
        $this->observe($a, $sk);
        $toId = $this->register($env, $this->hB);
        $tid = $this->createTarget($env, [$this->refApp01, $this->refApp02]);
        $this->confirmTarget($tid)->assertOk();
        return [$tid, $toId];
    }

    /** Fluxo completo até 'claimed', retornando [opId, execution_id]. */
    private function claim(int $env, string $a, string $sk, int $tid, int $toId): array
    {
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->assertStatus(201)->json('data.id');
        $this->approve($id)->assertOk();
        $data = $this->sigGet($a, $sk, '/connector/operations/next')->assertOk()->json('data');
        return [$id, $data['execution_id'], $data];
    }

    // ── gates de criação (pré-efeito) ─────────────────────────────────────────

    public function test_permission_promote_required(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $this->promote($this->userWith(['prosight.operations.rpo.manage']), $tid, $toId)->assertStatus(403);
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->assertStatus(201);
    }

    public function test_capability_unavailable_blocks(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $this->observe($a, $sk, [], ['cver' => 99]); // versão fora da allowlist → capability indisponível
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)
            ->assertStatus(422)->assertJsonPath('error', 'preview_ineligible')
            ->assertJsonFragment(['reasons' => ['publish_capability_unavailable']]);
    }

    public function test_activation_mode_not_hot_blocks(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $this->observe($a, $sk, [], ['mode' => 'requires_restart']);
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)
            ->assertStatus(422)->assertJsonPath('error', 'activation_mode_not_executable');
    }

    public function test_publish_unit_inconsistent_blocks(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $this->observe($a, $sk, [], ['pu' => [$this->refApp01 => 'U1', $this->refApp02 => 'U2']]); // 2 unidades → não é 1 só
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)
            ->assertStatus(422)->assertJsonPath('error', 'publish_unit_inconsistent');
    }

    public function test_from_equals_to_blocks(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk);
        $sameId = $this->register($env, $this->hA); // to == RPO ativo
        $tid = $this->createTarget($env, [$this->refApp01, $this->refApp02]);
        $this->confirmTarget($tid)->assertOk();
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $sameId)
            ->assertStatus(422)->assertJsonPath('error', 'preview_ineligible')
            ->assertJsonFragment(['reasons' => ['from_equals_to']]);
    }

    public function test_incompatible_version_blocks(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk);
        $incId = $this->register($env, $this->hB, ['appserver_versions' => ['9.9.9']]);
        $tid = $this->createTarget($env, [$this->refApp01, $this->refApp02]);
        $this->confirmTarget($tid)->assertOk();
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $incId)
            ->assertStatus(422)->assertJsonFragment(['reasons' => ['incompatible_appserver_version']]);
    }

    public function test_target_not_confirmed_blocks(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk);
        $toId = $this->register($env, $this->hB);
        $tid = $this->createTarget($env, [$this->refApp01, $this->refApp02]); // NÃO confirmado
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)
            ->assertStatus(422)->assertJsonPath('error', 'preview_ineligible');
    }

    public function test_hot_has_no_last_appserver_gate(): void
    {
        // target de 1 membro que é o ÚNICO up no ambiente → hot NÃO bloqueia (sem outage deliberado).
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->refApp01 => $this->hA], ['refs' => [$this->refApp01]]);
        $toId = $this->register($env, $this->hB);
        $tid = $this->createTarget($env, [$this->refApp01]);
        $this->confirmTarget($tid)->assertOk();
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->assertStatus(201);
    }

    // ── maker-checker N-of-M ──────────────────────────────────────────────────

    public function test_maker_cannot_approve(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $maker = $this->userWith(['prosight.operations.rpo.promote', 'prosight.operations.rpo.approve']);
        $id = $this->promote($maker, $tid, $toId)->json('data.id');
        $this->approveAs($id, $maker)->assertStatus(422)->assertJsonPath('error', 'maker_cannot_approve');
    }

    public function test_nofm_two_distinct_approvers_prod(): void
    {
        config(['connector.operations.rpo.required_approvals' => ['prod' => 2, 'default' => 1]]);
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->json('data.id');
        $c1 = $this->userWith(['prosight.operations.rpo.approve']); $c2 = $this->userWith(['prosight.operations.rpo.approve']);
        $this->approveAs($id, $c1)->assertOk();
        $this->assertSame('pending_approval', $this->show($id)['status']);   // 1/2 ainda não libera
        $this->approveAs($id, $c1)->assertStatus(409)->assertJsonPath('error', 'already_approved'); // mesmo checker não conta 2×
        $this->approveAs($id, $c2)->assertOk();
        $this->assertSame('dispatchable', $this->show($id)['status']);        // 2/2 → liberado
    }

    // ── caminho feliz + effect_started + last_successfully_published ──────────

    public function test_happy_hot_success_and_last_published(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        [$id, $eid, $view] = $this->claim($env, $a, $sk, $tid, $toId);
        // agent view carrega IDENTIDADE (artifact_id + sha256) — nunca bytes/path.
        $this->assertSame($this->hB, $view['rpo']['to_hash']);
        $this->assertSame($this->hA, $view['rpo']['from_hash']);
        $this->assertSame('hot', $view['rpo']['activation_mode']);
        $this->assertStringNotContainsString('/', json_encode($view['rpo']['publish_unit_id'] ?? ''));
        // barreira → efeito iniciado (2 marcadores DISTINTOS de journal).
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk(); // execution_committed
        $this->assertSame('execution_committed', $this->show($id)['status']);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid, 'phase' => 'effect_started'])->assertOk();
        $this->assertNotNull($this->show($id)['effect_started_at']);
        // apply hot → coleta correlacionada do target inteiro em to_hash, ambos up.
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB], ['opId' => $id]);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk()->assertJsonPath('status', 'verifying');
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
        // sucesso técnico → last_successfully_published (NÃO known_good).
        $tv = $this->targetView($tid);
        $this->assertSame($this->hB, $tv['last_successfully_published']['hash']);
        $this->assertNull($tv['last_known_good']);
    }

    public function test_effect_started_requires_barrier_first(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $toId);
        // effect_started ANTES da barreira (status=claimed) → rejeitado.
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid, 'phase' => 'effect_started'])->assertStatus(409);
    }

    // ── distribuídos (comunicação perdida) ───────────────────────────────────

    public function test_distributed_apply_result_lost_success(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $toId);
        $effect = 0;
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid, 'phase' => 'effect_started'])->assertOk();
        $effect = 1; // agente aplicou o RPO
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB], ['opId' => $id]); // result se perdeu
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('indeterminate', $this->show($id)['status']);
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
        $this->assertSame(1, $effect); // sem 2º apply
    }

    public function test_distributed_lost_ack_swap_not_occurred_noop(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $toId);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        // ACK perdido antes do apply: efeito 0, target permanece em from.
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hA], ['opId' => $id]);
        $this->forcePast($id, 'operational_deadline_at'); $this->show($id); // → indeterminate
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // cedo → aguarda
        $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('reconciled_noop', $this->reconcile($id)['status']);
    }

    public function test_periodic_snapshot_without_correlation_does_not_conclude(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $toId);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        // snapshot PERIÓDICO em to_hash SEM trigger.operation_id → NÃO conclui.
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB]);
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('reconciling', $this->reconcile($id)['status']);
        $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('unresolved', $this->reconcile($id)['status']); // janela vence sem coleta correlacionada
    }

    // ── divergências observadas (freeze / humano) ────────────────────────────

    public function test_unexpected_hash_contradicted(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $toId);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hX], ['opId' => $id]); // hash inesperado
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('contradicted', $this->reconcile($id)['status']);
    }

    public function test_partial_apply_contradicted(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $toId);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hA], ['opId' => $id]); // parcial: B/A
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // aguarda janela (evita flapping)
        $this->forcePast($id, 'execution_committed_at', 1000);
        $out = $this->reconcile($id);
        $this->assertSame('contradicted', $out['status']);
        $this->assertSame('partial_apply', $out['reconciliation_state']);
        $this->assertSame(1, ConnectorEvent::where('environment_id', $env)->where('event_type', 'operation_partial_apply')->count());
    }

    public function test_recovery_failed_member_down(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $toId);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        // RPO=to em ambos, mas APP02 não retornou disponível (hot: sem auto-restart, sem rollback).
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB], ['opId' => $id, 'up' => [$this->refApp02 => false]]);
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // indisponibilidade transiente antes da janela
        $this->forcePast($id, 'execution_committed_at', 1000);
        $out = $this->reconcile($id);
        $this->assertSame('contradicted', $out['status']);
        $this->assertSame('recovery_failed', $out['reconciliation_state']);
    }

    public function test_partial_apply_resolved_by_human(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $toId);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hA], ['opId' => $id]);
        $this->forcePast($id, 'operational_deadline_at'); $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('contradicted', $this->reconcile($id)['status']);
        $r = $this->resolve($id, 'failed')->assertOk()->json('data');
        $this->assertSame('failed', $r['status']);
        $this->assertSame('human', $r['outcome_authority']);
    }

    // ── revalidação no dispatch (topologia mudou entre aprovação e claim) ─────

    public function test_dispatch_block_capability_disappears(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->json('data.id');
        $this->approve($id)->assertOk();
        $this->observe($a, $sk, [], ['withCap' => false]); // capability sumiu
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
        $this->assertSame('publish_capability_unavailable', $this->blockReason($env));
    }

    public function test_dispatch_block_activation_mode_changed(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->json('data.id');
        $this->approve($id)->assertOk();
        $this->observe($a, $sk, [], ['mode' => 'requires_restart']); // hot → requires_restart após aprovação
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
        $this->assertSame('activation_mode_changed', $this->blockReason($env));
    }

    public function test_dispatch_block_from_hash_diverged(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->json('data.id');
        $this->approve($id)->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hX, $this->refApp02 => $this->hX]); // RPO ativo mudou p/ X
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
        $this->assertSame('from_hash_diverged', $this->blockReason($env));
    }

    public function test_dispatch_block_publish_unit_changed(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->json('data.id');
        $this->approve($id)->assertOk();
        $this->observe($a, $sk, [], ['pu' => [$this->refApp01 => 'U2', $this->refApp02 => 'U2']]); // unidade mudou
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
        $this->assertSame('publish_unit_changed', $this->blockReason($env));
    }

    public function test_dispatch_block_artifact_superseded(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->json('data.id');
        $this->approve($id)->assertOk();
        // artefato 'to' revisado (nova revisão supersede a aprovada) entre aprovação e dispatch.
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/rpo/artifacts/{$toId}/revise", ['provenance' => 'fix', 'compatibility' => ['appserver_versions' => ['12.1.2410']]])->assertStatus(201);
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
        $this->assertSame('artifact_superseded', $this->blockReason($env));
    }

    // ── at-most-once + concorrência ──────────────────────────────────────────

    public function test_at_most_once_after_connector_restart(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $toId);
        $journal = [$eid => ['committed' => false, 'effect' => 0]];
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid])->assertOk();
        $journal[$eid]['committed'] = true;
        $apply = function ($e) use (&$journal) { if ($journal[$e]['committed'] && $journal[$e]['effect'] === 0) { $journal[$e]['effect'] = 1; } };
        $apply($eid);
        // Conector reinicia (nova identidade); recupera por AMBIENTE via /current.
        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/v1/prosight/connector/agents/{$a}");
        [$a2, $sk2] = $this->enrollAgent($env);
        $cur = $this->sigGet($a2, $sk2, '/connector/operations/current')->assertOk()->json('data');
        $this->assertSame($eid, $cur['execution_id']);
        $apply($cur['execution_id']); // journal impede 2º apply (mesmo execution_id)
        $this->assertSame(1, $journal[$eid]['effect']);
        $this->observe($a2, $sk2, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB], ['opId' => $id]);
        $this->forcePast($id, 'operational_deadline_at'); $this->show($id);
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
    }

    public function test_one_live_operation_per_environment(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $toId] = $this->setup2($env, $a, $sk);
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->assertStatus(201);
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $toId)->assertStatus(409)->assertJsonPath('error', 'operation_in_flight');
    }
}
