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
 * Connector-5.2b — rpo_promote com activation_mode=requires_restart (SÓ restart_strategy=rolling).
 * Sucesso FORTE = DUPLA transição causal por membro: RPO=B E process_instance_id P2≠P1 (≠null) E up.
 * NÃO basta B+up. Publicação+restart = UMA operação/execution_id (sem C4 externo). B publicado sem restart
 * → recovery_failed (não noop, não auto-A). Tolerância rolling dentro da janela. Herda window+last-AppServer.
 * strategy ausente/simultaneous → fail-closed (nunca vira simultaneous). Caminho hot INTOCADO.
 */
class ConnectorOperationRpoRestartRollingTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private string $r1 = '11111111-aaaa-4bbb-8ccc-111111111111';
    private string $r2 = '22222222-aaaa-4bbb-8ccc-222222222222';
    private string $hA; private string $hB; private string $hX;
    // P1 (pré) e P2 (pós-restart) por membro
    private array $P1; private array $P2;

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
            'connector.operations.rpo_promote.operational_deadline' => 180, 'connector.operations.rpo_promote.reconcile_window' => 300,
            'connector.operations.rpo_promote.requires_restart.operational_deadline' => 600,
            'connector.operations.rpo_promote.requires_restart.reconcile_window' => 600,
            'connector.operations.rpo_promote.requires_restart.min_available' => 1,
            'connector.operations.rpo_promote.requires_restart.window' => ['enabled' => false, 'timezone' => 'UTC', 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '00:00', 'end' => '23:59'],
            'connector.operations.rpo.executable_activation_modes' => ['hot', 'requires_restart'],
            'connector.operations.rpo.executable_restart_strategies' => ['rolling'],
            'connector.operations.rpo.required_approvals' => ['prod' => 1, 'default' => 1],
            'connector.operations.rpo.supported_capabilities' => [['name' => 'rpo_publish', 'contract_version' => 1]],
            'connector.presence_online' => 75, 'connector.presence_offline' => 300,
        ]);
        $this->custA = Customer::factory()->create();
        $this->hA = str_repeat('a', 64); $this->hB = str_repeat('b', 64); $this->hX = str_repeat('c', 64);
        $this->P1 = [$this->r1 => 'P1AAAA1111bbbb2222', $this->r2 => 'P1BBBB1111bbbb2222'];
        $this->P2 = [$this->r1 => 'P2AAAA9999dddd8888', $this->r2 => 'P2BBBB9999dddd8888'];
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
        return DB::table('env_environments')->insertGetId(['customer_id' => $c->id, 'vault_id' => $vault->id, 'name' => 'Produção', 'type' => 'prod', 'status' => 'online', 'created_at' => now(), 'updated_at' => now()]);
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

    /** appservers com piid por membro (up default). */
    private function appservers(array $refs, array $piidByRef, array $up = []): array
    {
        $names = [$this->r1 => 'APP01', $this->r2 => 'APP02'];
        $out = [];
        foreach ($refs as $ref) {
            $u = $up[$ref] ?? true;
            $row = ['ref' => $ref, 'name' => $names[$ref], 'up' => $u, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => $u ? 50 : 0];
            if ($u && ($piidByRef[$ref] ?? null) !== null) { $row['process_instance_id'] = $piidByRef[$ref]; }
            $out[] = $row;
        }
        return $out;
    }
    private function rpoEntries(array $refs, array $hashByRef): array
    {
        $out = [];
        foreach ($refs as $ref) { $h = $hashByRef[$ref] ?? null; if ($h !== null) { $out[] = ['appserver_ref' => $ref, 'hash' => $h, 'version' => 'TTTP', 'publish_unit_id' => 'U1']; } }
        return $out;
    }
    /** observe(hashByRef, opts{refs,piid,up,opId,mode,strategy,cver,withCap}) — default requires_restart/rolling, hA, P1. */
    private function observe(string $a, string $sk, array $hash = [], array $opts = []): void
    {
        $refs = $opts['refs'] ?? [$this->r1, $this->r2];
        $hash = $hash ?: array_fill_keys($refs, $this->hA);
        $piid = $opts['piid'] ?? $this->P1;
        $this->sigPost($a, $sk, '/connector/heartbeat', ['observed_at' => time()])->assertOk();
        $body = ['observed_at' => time(), 'appservers' => $this->appservers($refs, $piid, $opts['up'] ?? []), 'rest' => [], 'rpo' => $this->rpoEntries($refs, $hash)];
        if (($opts['withCap'] ?? true)) {
            $cap = ['name' => 'rpo_publish', 'adapter' => 'totvs_x', 'contract_version' => $opts['cver'] ?? 1, 'operations' => ['promote'], 'activation_mode' => $opts['mode'] ?? 'requires_restart'];
            if (array_key_exists('strategy', $opts)) { if ($opts['strategy'] !== null) { $cap['restart_strategy'] = $opts['strategy']; } } else { $cap['restart_strategy'] = 'rolling'; }
            $body['capabilities'] = [$cap];
        }
        if (! empty($opts['opId'])) { $body['trigger'] = ['type' => 'operation', 'operation_id' => $opts['opId']]; }
        $this->sigPost($a, $sk, '/connector/inventory', $body)->assertOk();
    }
    private function ackP(string $a, string $sk, int $id, string $eid, string $phase): \Illuminate\Testing\TestResponse
    {
        return $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid, 'phase' => $phase]);
    }
    private function register(int $env, string $hash): int { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/artifacts/register", ['hash' => $hash, 'provenance' => 'GMUD', 'compatibility' => ['appserver_versions' => ['12.1.2410']], 'version' => 'TTTP'])->json('data.id'); }
    private function createTarget(int $env, array $refs): int { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/targets", ['name' => 'fin', 'appserver_refs' => $refs])->json('data.id'); }
    private function confirmTarget(int $tid): void { $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/confirm")->assertOk(); }
    private function promote(User $u, int $tid, int $toId, array $extra = []): \Illuminate\Testing\TestResponse { return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/promote", array_merge(['to_artifact_id' => $toId, 'reason' => 'restart promote'], $extra)); }
    private function approve(int $id): void { $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve")->assertOk(); }
    private function reconcile(int $id): array { return $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/reconcile")->json('data'); }
    private function show(int $id): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/operations/{$id}")->json('data'); }
    private function forcePast(int $id, string $col, int $s = 5): void { ConnectorOperation::whereKey($id)->update([$col => now()->subSeconds($s)]); }

    /** Setup: target 2 membros confirmado em A; cap requires_restart/rolling. Retorna [env,a,sk,tid,artB]. */
    private function scene(array $observeOpts = []): array
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [], $observeOpts);
        $artB = $this->register($env, $this->hB);
        $tid = $this->createTarget($env, [$this->r1, $this->r2]);
        $this->confirmTarget($tid);
        return [$env, $a, $sk, $tid, $artB];
    }
    private function claim(int $env, string $a, string $sk, int $tid, int $artB, array $extra = []): array
    {
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB, $extra)->assertStatus(201)->json('data.id');
        $this->approve($id);
        $data = $this->sigGet($a, $sk, '/connector/operations/next')->assertOk()->json('data');
        return [$id, $data['execution_id'], $data];
    }
    /** Emite os 3 marcadores (committed → publish → restart). */
    private function crossBarrier(string $a, string $sk, int $id, string $eid): void
    {
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        $this->ackP($a, $sk, $id, $eid, 'publish_effect_started')->assertOk();
        $this->ackP($a, $sk, $id, $eid, 'restart_effect_started')->assertOk();
    }

    // ── gates de criação ──────────────────────────────────────────────────────

    public function test_strategy_absent_blocks_never_simultaneous(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene(['strategy' => null]); // capability sem restart_strategy
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)
            ->assertStatus(422)->assertJsonPath('error', 'restart_strategy_not_executable');
    }

    public function test_simultaneous_blocked_this_version(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene(['strategy' => 'simultaneous']);
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)
            ->assertStatus(422)->assertJsonPath('error', 'restart_strategy_not_executable');
    }

    public function test_member_without_piid_blocks(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene(['piid' => [$this->r1 => $this->P1[$this->r1], $this->r2 => null]]); // r2 sem piid
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)
            ->assertStatus(422)->assertJsonPath('error', 'process_instance_capability_required');
    }

    public function test_multi_member_rolling_no_override(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene(); // 2 membros → rolling preserva ≥1 up → sem override
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->assertStatus(201);
    }

    public function test_single_member_blocks_without_override(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->r1 => $this->hA], ['refs' => [$this->r1]]);
        $artB = $this->register($env, $this->hB);
        $tid = $this->createTarget($env, [$this->r1]);
        $this->confirmTarget($tid);
        // single-member requires_restart → outage inevitável → bloqueia sem override
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)
            ->assertStatus(422)->assertJsonPath('error', 'last_appserver_restart_blocked');
        // com emergency_override MAS sem rpo.override → 403
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB, ['emergency_override' => true])
            ->assertStatus(403)->assertJsonPath('error', 'override_permission_required');
        // com rpo.override → 201
        $this->promote($this->userWith(['prosight.operations.rpo.promote', 'prosight.operations.rpo.override']), $tid, $artB, ['emergency_override' => true])
            ->assertStatus(201)->assertJsonPath('data.emergency_override', true);
    }

    public function test_window_closed_blocks(): void
    {
        config(['connector.operations.rpo_promote.requires_restart.window' => ['enabled' => true, 'timezone' => 'UTC', 'days' => [], 'start' => '00:00', 'end' => '23:59']]); // days vazio → sempre fechada
        [$env, $a, $sk, $tid, $artB] = $this->scene();
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)
            ->assertStatus(422)->assertJsonPath('error', 'maintenance_window_closed');
    }

    // ── caminho feliz + marcadores + agent view ───────────────────────────────

    public function test_happy_rolling_success_requires_piid_transition(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene();
        [$id, $eid, $view] = $this->claim($env, $a, $sk, $tid, $artB);
        $this->assertSame('requires_restart', $view['rpo']['activation_mode']);
        $this->assertSame('rolling', $view['rpo']['restart_strategy']);
        $this->assertSame($this->P1, $view['rpo']['member_from_piid']); // P1 por membro na payload
        $this->crossBarrier($a, $sk, $id, $eid);
        $shown = $this->show($id);
        $this->assertNotNull($shown['publish_effect_started_at']);
        $this->assertNotNull($shown['restart_effect_started_at']);
        // coleta correlacionada: RPO=B + P2≠P1 + up em TODOS
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['opId' => $id, 'piid' => $this->P2]);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk();
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
    }

    public function test_b_up_without_restart_is_recovery_failed_not_success(): void
    {
        // RPO=B em todos + up, MAS process_instance_id INALTERADO (P1) → publicado mas NÃO ativo → recovery_failed.
        [$env, $a, $sk, $tid, $artB] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $artB);
        $this->crossBarrier($a, $sk, $id, $eid);
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['opId' => $id, 'piid' => $this->P1]); // B + P1 (não reiniciou)
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // dentro da janela → tolera
        $this->forcePast($id, 'execution_committed_at', 1000); // janela vence
        $out = $this->reconcile($id);
        $this->assertSame('contradicted', $out['status']);
        $this->assertSame('recovery_failed', $out['reconciliation_state']);
    }

    public function test_rolling_intermediate_is_reconciling_not_recovery_failed(): void
    {
        // B/P2, B/P1 → APP01 reiniciou, APP02 na fila → NÃO é recovery_failed imediato (dentro da janela).
        [$env, $a, $sk, $tid, $artB] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $artB);
        $this->crossBarrier($a, $sk, $id, $eid);
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['opId' => $id, 'piid' => [$this->r1 => $this->P2[$this->r1], $this->r2 => $this->P1[$this->r2]]]);
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // rolling em progresso
        // depois APP02 também reinicia → success
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['opId' => $id, 'piid' => $this->P2]);
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
    }

    public function test_member_down_during_restart_tolerated_in_window(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $artB);
        $this->crossBarrier($a, $sk, $id, $eid);
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['opId' => $id, 'piid' => [$this->r1 => $this->P2[$this->r1], $this->r2 => $this->P1[$this->r2]], 'up' => [$this->r2 => false]]); // r2 down (reiniciando)
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // transiente → tolera
        $this->forcePast($id, 'execution_committed_at', 1000);
        $out = $this->reconcile($id);
        $this->assertSame('recovery_failed', $out['reconciliation_state']); // janela venceu sem recuperar
    }

    public function test_publish_not_occurred_is_noop(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $artB);
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['opId' => $id, 'piid' => $this->P1]); // publicação não ocorreu
        $this->forcePast($id, 'operational_deadline_at'); $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('reconciled_noop', $this->reconcile($id)['status']);
    }

    public function test_mixed_rpo_is_partial_apply(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $artB);
        $this->crossBarrier($a, $sk, $id, $eid);
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hA], ['opId' => $id, 'piid' => $this->P2]); // uns B uns A na unidade única
        $this->forcePast($id, 'operational_deadline_at'); $this->forcePast($id, 'execution_committed_at', 1000);
        $out = $this->reconcile($id);
        $this->assertSame('partial_apply', $out['reconciliation_state']);
    }

    public function test_unexpected_hash_contradicted(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $artB);
        $this->crossBarrier($a, $sk, $id, $eid);
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hX], ['opId' => $id, 'piid' => $this->P2]);
        $this->forcePast($id, 'operational_deadline_at'); $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('contradicted', $this->reconcile($id)['status']);
    }

    // ── revalidação no dispatch ───────────────────────────────────────────────

    public function test_dispatch_block_strategy_changed(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene();
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->json('data.id');
        $this->approve($id);
        $this->observe($a, $sk, [], ['strategy' => 'simultaneous']); // strategy muda após aprovação
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
    }

    // ── distribuído ───────────────────────────────────────────────────────────

    public function test_distributed_result_lost_success(): void
    {
        [$env, $a, $sk, $tid, $artB] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $artB);
        $this->crossBarrier($a, $sk, $id, $eid);
        $effect = 1;
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['opId' => $id, 'piid' => $this->P2]); // publish+restart ok, result perdido
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('indeterminate', $this->show($id)['status']);
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
        $this->assertSame(1, $effect);
    }

    // ── hot permanece intocado ────────────────────────────────────────────────

    public function test_hot_path_unchanged_no_piid_requirement(): void
    {
        // Com capability hot (sem restart_strategy, sem exigência de piid p/ reconciliar), o promote hot segue
        // válido: B + up = success (NÃO exige transição de piid). Prova que o caminho hot não regrediu.
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [], ['mode' => 'hot', 'strategy' => null]);
        $artB = $this->register($env, $this->hB);
        $tid = $this->createTarget($env, [$this->r1, $this->r2]);
        $this->confirmTarget($tid);
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->assertStatus(201)->json('data.id');
        $this->approve($id);
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->json('data.execution_id');
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        $this->ackP($a, $sk, $id, $eid, 'effect_started')->assertOk();
        // B + up + MESMO piid (P1) → em hot é SUCCESS (hot não exige transição de piid)
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['opId' => $id, 'piid' => $this->P1, 'mode' => 'hot', 'strategy' => null]);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk();
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
    }
}
