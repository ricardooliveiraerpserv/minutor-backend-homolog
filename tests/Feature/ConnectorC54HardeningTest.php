<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
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
 * Connector-5.4 — Hardening/readiness do ciclo RPO hot (SEM nova capacidade destrutiva). Prova:
 *  - FREEZE: contradicted/partial_apply/recovery_failed/unresolved bloqueiam nova destrutiva (promote/rollback)
 *    no ambiente (índice 1-op-viva) — incidente NÃO pode ser contornado sem resolução governada.
 *  - RESOLUÇÃO governada: perm + reason obrigatórios; SEM disposition 'success' (não reescreve p/ sucesso);
 *    PRESERVA reconciliation_state (evidência); grava resolution first-class + timeline; anti-IDOR; libera a trava.
 *  - AUDITORIA ponta-a-ponta read-only (cadeia + timeline).
 *  - LEAKAGE: zero path/credencial/bytes/staging handle no adminView/audit/agent view/timeline.
 */
class ConnectorC54HardeningTest extends TestCase
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
            'connector.operations.rpo_promote.operational_deadline' => 180, 'connector.operations.rpo_promote.reconcile_window' => 300,
            'connector.operations.rpo_rollback.operational_deadline' => 180, 'connector.operations.rpo_rollback.reconcile_window' => 300,
            'connector.operations.rpo.executable_activation_modes' => ['hot'],
            'connector.operations.rpo.required_approvals' => ['prod' => 1, 'default' => 1],
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
    private function userWith(array $perms, ?Customer $c = null): User
    {
        $u = User::factory()->create(['type' => 'consultor', 'extra_permissions' => $perms]);
        $proj = Project::factory()->create(['customer_id' => ($c ?? $this->custA)->id]);
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

    private function appservers(array $up): array
    {
        $out = [];
        foreach ([$this->refApp01, $this->refApp02] as $ref) {
            $u = $up[$ref] ?? true;
            $out[] = ['ref' => $ref, 'name' => 'APP', 'up' => $u, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => $u ? 50 : 0] + ($u ? ['process_instance_id' => 'PI' . substr(md5($ref), 0, 18)] : []);
        }
        return $out;
    }
    private function observe(string $a, string $sk, array $hash = [], ?int $opId = null, array $up = []): void
    {
        $refs = [$this->refApp01, $this->refApp02];
        $hash = $hash ?: array_fill_keys($refs, $this->hA);
        $rpo = [];
        foreach ($refs as $ref) { if (($hash[$ref] ?? null) !== null) { $rpo[] = ['appserver_ref' => $ref, 'hash' => $hash[$ref], 'version' => 'TTTP', 'publish_unit_id' => 'U1']; } }
        $this->sigPost($a, $sk, '/connector/heartbeat', ['observed_at' => time()])->assertOk();
        $body = ['observed_at' => time(), 'appservers' => $this->appservers($up), 'rest' => [], 'rpo' => $rpo, 'capabilities' => [['name' => 'rpo_publish', 'adapter' => 'totvs_x', 'contract_version' => 1, 'operations' => ['promote', 'rollback'], 'activation_mode' => 'hot']]];
        if ($opId) { $body['trigger'] = ['type' => 'operation', 'operation_id' => $opId]; }
        $this->sigPost($a, $sk, '/connector/inventory', $body)->assertOk();
    }
    private function ackP(string $a, string $sk, int $id, string $eid, string $phase): void
    {
        $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid, 'phase' => $phase])->assertOk();
    }

    private function register(int $env, string $hash): int { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/artifacts/register", ['hash' => $hash, 'provenance' => 'GMUD', 'compatibility' => ['appserver_versions' => ['12.1.2410']], 'version' => 'TTTP'])->json('data.id'); }
    private function createTarget(int $env, array $refs): int { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/targets", ['name' => 'fin', 'appserver_refs' => $refs])->json('data.id'); }
    private function confirmTarget(int $tid): void { $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/confirm")->assertOk(); }
    private function qualify(int $tid, int $artId): int { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/qualify", ['artifact_id' => $artId, 'reason' => 'kg'])->json('data.id'); }
    private function promote(User $u, int $tid, int $toId): \Illuminate\Testing\TestResponse { return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/promote", ['to_artifact_id' => $toId, 'reason' => 'promo']); }
    private function rollback(User $u, int $tid, int $qid): \Illuminate\Testing\TestResponse { return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/rollback", ['qualification_id' => $qid, 'reason' => 'rb']); }
    private function approve(int $id): void { $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve")->assertOk(); }
    private function reconcile(int $id): array { return $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/reconcile")->json('data'); }
    private function show(int $id): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/operations/{$id}")->json('data'); }
    private function audit(int $id, ?User $u = null): \Illuminate\Testing\TestResponse { return $this->actingAs($u ?? $this->admin(), 'sanctum')->getJson("/api/v1/prosight/operations/{$id}/audit"); }
    private function resolve(int $id, array $body, ?User $u = null): \Illuminate\Testing\TestResponse { return $this->actingAs($u ?? $this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/resolve", $body); }
    private function forcePast(int $id, string $col, int $s = 5): void { ConnectorOperation::whereKey($id)->update([$col => now()->subSeconds($s)]); }

    /** Setup: A known_good; target confirmado; observado em A (promote A→B natural). Rollback tests movem p/ B. */
    private function scene(): array
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk); // hA
        $artA = $this->register($env, $this->hA); $artB = $this->register($env, $this->hB);
        $tid = $this->createTarget($env, [$this->refApp01, $this->refApp02]);
        $this->confirmTarget($tid);
        $qA = $this->qualify($tid, $artA);
        return [$env, $a, $sk, $tid, $qA, $artA, $artB];
    }
    private function moveToB(string $a, string $sk): void { $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB]); }

    /** Leva um promote a 'contradicted' (hash inesperado). Retorna opId. */
    private function makeContradicted(int $env, string $a, string $sk, int $tid, int $artB): int
    {
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->json('data.id');
        $this->approve($id);
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->json('data.execution_id');
        $this->ackP($a, $sk, $id, $eid, 'execution_committed');
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hX], $id); // inesperado
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('contradicted', $this->reconcile($id)['status']);
        return $id;
    }

    // ── FREEZE ────────────────────────────────────────────────────────────────

    /** Após um incidente, prova que promote E rollback (ambos senão-elegíveis) batem em operation_in_flight. */
    private function assertFrozenBothDirections(string $a, string $sk, int $tid, int $qA, int $artB): void
    {
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hA]); // consistente em A → promote A→B elegível
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->assertStatus(409)->assertJsonPath('error', 'operation_in_flight');
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB]); // consistente em B → rollback B→A elegível
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->assertStatus(409)->assertJsonPath('error', 'operation_in_flight');
    }

    public function test_contradicted_freezes_promote_and_rollback(): void
    {
        [$env, $a, $sk, $tid, $qA, , $artB] = $this->scene();
        $this->makeContradicted($env, $a, $sk, $tid, $artB);
        $this->assertFrozenBothDirections($a, $sk, $tid, $qA, $artB); // incidente vivo → não contorna
    }

    public function test_partial_apply_freezes_new_destructive(): void
    {
        [$env, $a, $sk, $tid, $qA, , $artB] = $this->scene();
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->json('data.id');
        $this->approve($id);
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->json('data.execution_id');
        $this->ackP($a, $sk, $id, $eid, 'execution_committed');
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hA], $id); // parcial
        $this->forcePast($id, 'operational_deadline_at'); $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('partial_apply', $this->reconcile($id)['reconciliation_state']);
        $this->assertFrozenBothDirections($a, $sk, $tid, $qA, $artB);
    }

    public function test_recovery_failed_freezes_new_destructive(): void
    {
        [$env, $a, $sk, $tid, $qA, , $artB] = $this->scene();
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->json('data.id');
        $this->approve($id);
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->json('data.execution_id');
        $this->ackP($a, $sk, $id, $eid, 'execution_committed');
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB], $id, [$this->refApp02 => false]); // B, A2 down
        $this->forcePast($id, 'operational_deadline_at'); $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('recovery_failed', $this->reconcile($id)['reconciliation_state']);
        $this->assertFrozenBothDirections($a, $sk, $tid, $qA, $artB);
    }

    public function test_unresolved_freezes_new_destructive(): void
    {
        [$env, $a, $sk, $tid, $qA, , $artB] = $this->scene();
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->json('data.id');
        $this->approve($id);
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->json('data.execution_id');
        $this->ackP($a, $sk, $id, $eid, 'execution_committed');
        // sem coleta correlacionada → após a janela, unresolved
        $this->forcePast($id, 'operational_deadline_at'); $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('unresolved', $this->reconcile($id)['status']);
        $this->assertFrozenBothDirections($a, $sk, $tid, $qA, $artB);
    }

    // ── RESOLUÇÃO GOVERNADA ───────────────────────────────────────────────────

    public function test_resolve_requires_permission(): void
    {
        [$env, $a, $sk, $tid, , , $artB] = $this->scene();
        $id = $this->makeContradicted($env, $a, $sk, $tid, $artB);
        $this->resolve($id, ['resolution' => 'failed', 'reason' => 'x'], $this->userWith(['prosight.operations.rpo.promote']))->assertStatus(403);
    }

    public function test_resolve_requires_reason(): void
    {
        [$env, $a, $sk, $tid, , , $artB] = $this->scene();
        $id = $this->makeContradicted($env, $a, $sk, $tid, $artB);
        $this->resolve($id, ['resolution' => 'failed'])->assertStatus(422);
    }

    public function test_resolve_rejects_success_disposition(): void
    {
        [$env, $a, $sk, $tid, , , $artB] = $this->scene();
        $id = $this->makeContradicted($env, $a, $sk, $tid, $artB);
        // 'success' não é aceito (autoridade física = C-2; humano nunca reescreve p/ sucesso)
        $this->resolve($id, ['resolution' => 'success', 'reason' => 'quero sucesso'])->assertStatus(422);
    }

    public function test_resolve_anti_idor(): void
    {
        [$env, $a, $sk, $tid, , , $artB] = $this->scene();
        $id = $this->makeContradicted($env, $a, $sk, $tid, $artB);
        $other = Customer::factory()->create();
        $this->resolve($id, ['resolution' => 'failed', 'reason' => 'x'], $this->userWith(['prosight.operations.rpo.approve'], $other))->assertStatus(404);
    }

    public function test_resolve_preserves_evidence_and_records(): void
    {
        [$env, $a, $sk, $tid, , , $artB] = $this->scene();
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->json('data.id');
        $this->approve($id);
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->json('data.execution_id');
        $this->ackP($a, $sk, $id, $eid, 'execution_committed');
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hA], $id); // parcial
        $this->forcePast($id, 'operational_deadline_at'); $this->forcePast($id, 'execution_committed_at', 1000);
        $this->reconcile($id);
        $eventsBefore = ConnectorEvent::where('environment_id', $env)->where('meta->operation_id', $id)->count();
        $r = $this->resolve($id, ['resolution' => 'failed', 'reason' => 'rollback manual executado fora de banda'])->assertOk()->json('data');
        // status terminal, autoridade humana, MAS reconciliation_state (evidência) PRESERVADO como partial_apply
        $this->assertSame('failed', $r['status']);
        $this->assertSame('human', $r['outcome_authority']);
        $this->assertSame('partial_apply', $r['reconciliation_state']); // NÃO reescreveu p/ success/contradicted
        $this->assertNotSame('reconciled_success', $r['status']);
        $this->assertSame('failed', $r['resolution']['disposition']);
        $this->assertSame('partial_apply', $r['resolution']['before']['reconciliation_state']);
        // timeline first-class + evidência anterior intacta (só ACRESCENTA evento)
        $this->assertGreaterThan($eventsBefore, ConnectorEvent::where('environment_id', $env)->where('meta->operation_id', $id)->count());
        $this->assertSame(1, ConnectorEvent::where('environment_id', $env)->where('event_type', 'operation_resolved')->where('meta->operation_id', $id)->count());
    }

    public function test_resolve_unfreezes_target_when_state_permits(): void
    {
        [$env, $a, $sk, $tid, $qA, , $artB] = $this->scene();
        $id = $this->makeContradicted($env, $a, $sk, $tid, $artB);
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hA]); // consistente em A
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->assertStatus(409); // frozen
        $this->resolve($id, ['resolution' => 'failed', 'reason' => 'tratado'])->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hA]); // estado atual permite A→B
        $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->assertStatus(201); // liberado
    }

    // ── AUDITORIA ─────────────────────────────────────────────────────────────

    public function test_audit_reconstructs_promote_chain(): void
    {
        [$env, $a, $sk, $tid, , $artA, $artB] = $this->scene();
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->json('data.id');
        $this->approve($id);
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->json('data.execution_id');
        $this->ackP($a, $sk, $id, $eid, 'execution_committed');
        $this->ackP($a, $sk, $id, $eid, 'effect_started');
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB], $id);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk();
        $this->reconcile($id);
        $data = $this->audit($id)->assertOk()->json('data');
        $chain = $data['chain'];
        $this->assertSame('rpo_promote', $chain['op_type']);
        $this->assertSame($this->hA, $chain['transition']['from_hash']);
        $this->assertSame($this->hB, $chain['transition']['to_hash']);
        $this->assertNotNull($chain['execution']['execution_id']);
        $this->assertNotNull($chain['execution']['execution_committed_at']);
        $this->assertNotNull($chain['execution']['effect_started_at']);
        $this->assertTrue((bool) $chain['correlated_collection']['correlated']);
        $this->assertSame('reconciled_success', $chain['decision']['status']);
        // timeline correlacionada presente (requested→approved→dispatched→claimed→committed→effect→verifying→success)
        $types = array_column($data['timeline'], 'event');
        foreach (['operation_requested', 'operation_execution_committed', 'operation_effect_started', 'operation_reconciled_success'] as $t) {
            $this->assertContains($t, $types);
        }
    }

    public function test_audit_reconstructs_rollback_chain_with_qualification(): void
    {
        [$env, $a, $sk, $tid, $qA] = $this->scene();
        $this->moveToB($a, $sk); // target em B → rollback B→A
        $id = $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->json('data.id');
        $this->approve($id);
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->json('data.execution_id');
        $this->ackP($a, $sk, $id, $eid, 'execution_committed');
        $data = $this->audit($id)->assertOk()->json('data');
        $this->assertSame('rpo_rollback', $data['chain']['op_type']);
        $this->assertSame($qA, $data['chain']['qualification']['id']); // autoridade nomeada na cadeia
        $this->assertSame($this->hB, $data['chain']['transition']['from_hash']);
        $this->assertSame($this->hA, $data['chain']['transition']['to_hash']);
    }

    public function test_audit_permission_and_idor(): void
    {
        [$env, $a, $sk, $tid, , , $artB] = $this->scene();
        $id = $this->promote($this->userWith(['prosight.operations.rpo.promote']), $tid, $artB)->json('data.id');
        // anti-IDOR: usuário de OUTRO customer (mesmo com view) → 404 (não vaza existência)
        $other = Customer::factory()->create();
        $this->audit($id, $this->userWith(['prosight.operations.view'], $other))->assertStatus(404);
        // dono com view → 200
        $this->audit($id, $this->userWith(['prosight.operations.view']))->assertOk();
    }

    // ── LEAKAGE ───────────────────────────────────────────────────────────────

    public function test_no_leakage_in_views_and_agent(): void
    {
        [$env, $a, $sk, $tid, $qA, $artA, $artB] = $this->scene();
        $this->moveToB($a, $sk); // target em B → rollback B→A
        $id = $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->json('data.id');
        $this->approve($id);
        $view = $this->sigGet($a, $sk, '/connector/operations/next')->json('data'); // agent view
        $eid = $view['execution_id'];
        $this->ackP($a, $sk, $id, $eid, 'execution_committed');
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hA], $id);
        $this->reconcile($id);
        $audit = $this->audit($id)->json('data');
        $blobs = [json_encode($view), json_encode($this->show($id)), json_encode($audit)];
        // sha256 (hash) É permitido; NÃO pode vazar path/credencial/bytes/staging/command/executável.
        $forbidden = '#(/opt/|/etc/|C:\\\\|\.rpo\b|\.ini\b|password|secret|senha|bearer |token"|staging_handle|/usr/|/var/|\bcmd\b|executabl|"bytes"|base64,)#i';
        foreach ($blobs as $b) {
            $this->assertSame(0, preg_match($forbidden, $b), 'leakage detectado em: ' . substr($b, 0, 120));
        }
        // agent view NÃO expõe qualification_id nem estruturas administrativas — só identidade física.
        $this->assertArrayNotHasKey('qualification', $view['rpo'] ?? []);
        $this->assertArrayHasKey('to_hash', $view['rpo']);
    }
}
