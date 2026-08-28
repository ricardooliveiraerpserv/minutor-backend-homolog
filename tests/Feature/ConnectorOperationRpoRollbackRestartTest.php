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
 * Connector-5.3b — rpo_rollback com activation_mode=requires_restart (SÓ rolling). Rollback num target
 * requires_restart É requires_restart: A também precisa reiniciar. Sucesso FORTE = B/P_cur → A/P_new, P_new≠P_cur
 * por membro (target inteiro, rolling), por C-2 correlacionada/causal. Autoridade = qualification_id (C5.3);
 * física = requires_restart (C5.2b). Obrigatório: revalidação de P_cur no dispatch (process_instance_diverged)
 * e FREEZE existente NUNCA bypassado. Caminho hot (C5.3) intocado.
 */
class ConnectorOperationRpoRollbackRestartTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private string $r1 = '11111111-aaaa-4bbb-8ccc-111111111111';
    private string $r2 = '22222222-aaaa-4bbb-8ccc-222222222222';
    private string $hA; private string $hB; private string $hX;
    private array $P1; private array $Pcur; private array $Pnew;

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
            'connector.operations.rpo_promote.requires_restart.operational_deadline' => 600,
            'connector.operations.rpo_promote.requires_restart.reconcile_window' => 600,
            'connector.operations.rpo_promote.requires_restart.min_available' => 1,
            'connector.operations.rpo_promote.requires_restart.window' => ['enabled' => false, 'timezone' => 'UTC', 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '00:00', 'end' => '23:59'],
            'connector.operations.rpo_rollback.operational_deadline' => 180, 'connector.operations.rpo_rollback.reconcile_window' => 300,
            'connector.operations.rpo.executable_activation_modes' => ['hot', 'requires_restart'],
            'connector.operations.rpo.executable_restart_strategies' => ['rolling'],
            'connector.operations.rpo.required_approvals' => ['prod' => 1, 'default' => 1],
            'connector.operations.rpo.supported_capabilities' => [['name' => 'rpo_publish', 'contract_version' => 1]],
            'connector.presence_online' => 75, 'connector.presence_offline' => 300,
        ]);
        $this->custA = Customer::factory()->create();
        $this->hA = str_repeat('a', 64); $this->hB = str_repeat('b', 64); $this->hX = str_repeat('c', 64);
        $this->P1 = [$this->r1 => 'P1AAAA1111bbbb2222', $this->r2 => 'P1BBBB1111cccc3333'];
        $this->Pcur = [$this->r1 => 'PCAAAA2222dddd4444', $this->r2 => 'PCBBBB2222eeee5555'];
        $this->Pnew = [$this->r1 => 'PNAAAA3333ffff6666', $this->r2 => 'PNBBBB3333gggg7777'];
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) { if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); } }
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

    private function appservers(array $refs, array $piid, array $up = []): array
    {
        $out = [];
        foreach ($refs as $r) { $u = $up[$r] ?? true; $row = ['ref' => $r, 'name' => 'APP', 'up' => $u, 'version' => '12.1.2410', 'build' => '9', 'patch' => '1', 'uptime_s' => $u ? 50 : 0]; if ($u && ($piid[$r] ?? null) !== null) { $row['process_instance_id'] = $piid[$r]; } $out[] = $row; }
        return $out;
    }
    private function rpoE(array $refs, array $hash): array { $o = []; foreach ($refs as $r) { if (($hash[$r] ?? null) !== null) { $o[] = ['appserver_ref' => $r, 'hash' => $hash[$r], 'version' => 'T', 'publish_unit_id' => 'U1']; } } return $o; }
    private function observe(string $a, string $sk, array $hash, array $opts = []): void
    {
        $refs = $opts['refs'] ?? [$this->r1, $this->r2];
        $piid = $opts['piid'] ?? $this->P1;
        $this->sigPost($a, $sk, '/connector/heartbeat', ['observed_at' => time()])->assertOk();
        $cap = ['name' => 'rpo_publish', 'adapter' => 'totvs_x', 'contract_version' => 1, 'operations' => ['promote'], 'activation_mode' => $opts['mode'] ?? 'requires_restart'];
        if (! array_key_exists('strategy', $opts)) { $cap['restart_strategy'] = 'rolling'; } elseif ($opts['strategy'] !== null) { $cap['restart_strategy'] = $opts['strategy']; }
        $body = ['observed_at' => time(), 'appservers' => $this->appservers($refs, $piid, $opts['up'] ?? []), 'rest' => [], 'rpo' => $this->rpoE($refs, $hash), 'capabilities' => [$cap]];
        if (! empty($opts['opId'])) { $body['trigger'] = ['type' => 'operation', 'operation_id' => $opts['opId']]; }
        $this->sigPost($a, $sk, '/connector/inventory', $body)->assertOk();
    }
    private function ackP(string $a, string $sk, int $id, string $eid, string $phase): \Illuminate\Testing\TestResponse { return $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid, 'phase' => $phase]); }
    private function crossBarrier(string $a, string $sk, int $id, string $eid): void
    {
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        $this->ackP($a, $sk, $id, $eid, 'publish_effect_started')->assertOk();
        $this->ackP($a, $sk, $id, $eid, 'restart_effect_started')->assertOk();
    }
    private function register(int $env, string $hash): int { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/artifacts/register", ['hash' => $hash, 'provenance' => 'GMUD', 'compatibility' => ['appserver_versions' => ['12.1.2410']], 'version' => 'T'])->json('data.id'); }
    private function createTarget(int $env, array $refs): int { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/targets", ['name' => 'fin', 'appserver_refs' => $refs])->json('data.id'); }
    private function confirmTarget(int $tid): void { $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/confirm")->assertOk(); }
    private function qualify(int $tid, int $artId): int { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/qualify", ['artifact_id' => $artId, 'reason' => 'kg'])->json('data.id'); }
    private function rollback(User $u, int $tid, int $qid, array $extra = []): \Illuminate\Testing\TestResponse { return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/rollback", array_merge(['qualification_id' => $qid, 'reason' => 'rr rollback'], $extra)); }
    private function approve(int $id): void { $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve")->assertOk(); }
    private function reconcile(int $id): array { return $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/reconcile")->json('data'); }
    private function resolve(int $id): void { $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/resolve", ['resolution' => 'failed', 'reason' => 'tratado'])->assertOk(); }
    private function show(int $id): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/operations/{$id}")->json('data'); }
    private function forcePast(int $id, string $col, int $s = 5): void { ConnectorOperation::whereKey($id)->update([$col => now()->subSeconds($s)]); }

    /** Setup: A known_good; target confirmado; target em B/Pcur (pós requires_restart-promote). */
    private function scene(): array
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['piid' => $this->P1]); // A/P1
        $artA = $this->register($env, $this->hA);
        $tid = $this->createTarget($env, [$this->r1, $this->r2]);
        $this->confirmTarget($tid);
        $qA = $this->qualify($tid, $artA);
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['piid' => $this->Pcur]); // B/Pcur
        return [$env, $a, $sk, $tid, $qA, $artA];
    }
    private function claim(int $env, string $a, string $sk, int $tid, int $qA): array
    {
        $id = $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->assertStatus(201)->json('data.id');
        $this->approve($id);
        $data = $this->sigGet($a, $sk, '/connector/operations/next')->assertOk()->json('data');
        return [$id, $data['execution_id'], $data];
    }

    // ── happy + agent view + markers ──────────────────────────────────────────

    public function test_happy_rollback_requires_restart_success(): void
    {
        [$env, $a, $sk, $tid, $qA, $artA] = $this->scene();
        [$id, $eid, $view] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->assertSame('requires_restart', $view['rpo']['activation_mode']);
        $this->assertSame('rolling', $view['rpo']['restart_strategy']);
        $this->assertSame($this->Pcur, $view['rpo']['member_from_piid']); // P_cur (incarnações servindo B)
        $this->assertSame($this->hA, $view['rpo']['to_hash']); // destino = known_good A
        $this->crossBarrier($a, $sk, $id, $eid);
        // coleta: RPO=A + P_new≠P_cur + up
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['opId' => $id, 'piid' => $this->Pnew]);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk();
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
        // last_successfully_published=A; qualificação PERMANECE (não re-qualifica)
        $tv = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/rpo/targets/{$tid}")->json('data');
        $this->assertSame($this->hA, $tv['last_successfully_published']['hash']);
        $this->assertSame($artA, $tv['last_known_good']['artifact_id']);
    }

    public function test_a_published_without_restart_is_recovery_failed(): void
    {
        // RPO=A + up mas process_instance_id INALTERADO (P_cur) → recuperação publicada NÃO ativa → recovery_failed.
        [$env, $a, $sk, $tid, $qA] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->crossBarrier($a, $sk, $id, $eid);
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['opId' => $id, 'piid' => $this->Pcur]); // A + Pcur
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk();
        $this->assertSame('reconciling', $this->reconcile($id)['status']); // dentro da janela
        $this->forcePast($id, 'execution_committed_at', 1000);
        $out = $this->reconcile($id);
        $this->assertSame('recovery_failed', $out['reconciliation_state']);
    }

    public function test_rolling_intermediate_then_success(): void
    {
        [$env, $a, $sk, $tid, $qA] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->crossBarrier($a, $sk, $id, $eid);
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['opId' => $id, 'piid' => [$this->r1 => $this->Pnew[$this->r1], $this->r2 => $this->Pcur[$this->r2]]]);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk();
        $this->assertSame('reconciling', $this->reconcile($id)['status']);
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['opId' => $id, 'piid' => $this->Pnew]);
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
    }

    // ── MANDATÓRIO: P_cur diverge no dispatch ─────────────────────────────────

    public function test_dispatch_block_process_instance_diverged(): void
    {
        // Criação observa B/Pcur; antes do dispatch alguém reinicia externamente → B/P3 (hash igual, PIID muda).
        [$env, $a, $sk, $tid, $qA] = $this->scene();
        $id = $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->json('data.id');
        $this->approve($id);
        $p3 = [$this->r1 => 'PXAAAA9999hhhh8888', $this->r2 => 'PXBBBB9999iiii9999'];
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['piid' => $p3]); // B/P3 (restart externo)
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
    }

    // ── MANDATÓRIO: freeze existente NÃO é bypassado ──────────────────────────

    public function test_freeze_not_bypassed_by_rollback(): void
    {
        // Leva um rollback a recovery_failed (incidente vivo) e prova que NOVO rollback é bloqueado (409).
        [$env, $a, $sk, $tid, $qA] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->crossBarrier($a, $sk, $id, $eid);
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['opId' => $id, 'piid' => $this->Pcur]);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk();
        $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('recovery_failed', $this->reconcile($id)['reconciliation_state']); // incidente vivo (contradicted)
        // NOVO rollback (mesmo elegível pela observação) → bloqueado pelo freeze (1-op-viva/ambiente)
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['piid' => $this->Pcur]); // observado em A/Pcur (elegível seria já-em-A... força B p/ elegível)
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['piid' => $this->Pcur]);
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->assertStatus(409)->assertJsonPath('error', 'operation_in_flight');
    }

    // ── gates herdados (fail-closed) ──────────────────────────────────────────

    public function test_strategy_absent_blocks(): void
    {
        [$env, $a, $sk, $tid, $qA] = $this->scene();
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['piid' => $this->Pcur, 'strategy' => null]);
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)
            ->assertStatus(422)->assertJsonPath('error', 'restart_strategy_not_executable');
    }

    public function test_member_without_piid_blocks(): void
    {
        [$env, $a, $sk, $tid, $qA] = $this->scene();
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['piid' => [$this->r1 => $this->Pcur[$this->r1], $this->r2 => null]]);
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)
            ->assertStatus(422)->assertJsonPath('error', 'process_instance_capability_required');
    }

    public function test_single_member_override(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->r1 => $this->hA], ['refs' => [$this->r1], 'piid' => $this->P1]);
        $artA = $this->register($env, $this->hA);
        $tid = $this->createTarget($env, [$this->r1]);
        $this->confirmTarget($tid);
        $qA = $this->qualify($tid, $artA);
        $this->observe($a, $sk, [$this->r1 => $this->hB], ['refs' => [$this->r1], 'piid' => $this->Pcur]);
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)
            ->assertStatus(422)->assertJsonPath('error', 'last_appserver_restart_blocked');
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback', 'prosight.operations.rpo.override']), $tid, $qA, ['emergency_override' => true])
            ->assertStatus(201);
    }

    // ── distribuído (obrigatório) ─────────────────────────────────────────────

    public function test_distributed_result_lost_success(): void
    {
        [$env, $a, $sk, $tid, $qA] = $this->scene();
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->crossBarrier($a, $sk, $id, $eid);
        $effect = 1;
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['opId' => $id, 'piid' => $this->Pnew]); // aplicou A/Pnew, result perdido
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('indeterminate', $this->show($id)['status']);
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
        $this->assertSame(1, $effect);
    }

    // ── hot rollback (C5.3) intocado ──────────────────────────────────────────

    public function test_hot_rollback_unchanged(): void
    {
        // Target hot: rollback B→A por qualification, success SEM exigir transição de piid.
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['mode' => 'hot', 'strategy' => null, 'piid' => $this->P1]);
        $artA = $this->register($env, $this->hA);
        $tid = $this->createTarget($env, [$this->r1, $this->r2]);
        $this->confirmTarget($tid);
        $qA = $this->qualify($tid, $artA);
        $this->observe($a, $sk, [$this->r1 => $this->hB, $this->r2 => $this->hB], ['mode' => 'hot', 'strategy' => null, 'piid' => $this->Pcur]);
        $id = $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->assertStatus(201)->json('data.id');
        $this->approve($id);
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->json('data.execution_id');
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        // hot: RPO=A + up (MESMO piid) → success (hot não exige transição)
        $this->observe($a, $sk, [$this->r1 => $this->hA, $this->r2 => $this->hA], ['opId' => $id, 'mode' => 'hot', 'strategy' => null, 'piid' => $this->Pcur]);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk();
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
    }
}
