<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorOperation;
use App\Models\Customer;
use App\Models\Project;
use App\Models\RpoTarget;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Connector-5.3 — rpo_rollback (SÓ activation_mode=hot). MESMA física hot from→to do C5.2; muda a AUTORIDADE
 * do destino: uma QUALIFICAÇÃO known_good CONTEXTUAL válida, NOMEADA (qualification_id). from_hash = observado
 * atual (mundo). Quatro barreiras: preview(informar) → create(autorizar criação, REAVALIA) → dispatch(autorizar
 * execução) → agente(efeito físico). Validade da qualificação é gate ATÉ execution_committed. already_at por HASH
 * efetivo. Sem auto-substituição de qualificação equivalente. Sucesso → last_successfully_published (NÃO re-qualifica).
 * Prova B→known_good A→rollback→A + os 2 distribuídos + os 5 gates novos. ZERO bytes/path.
 */
class ConnectorOperationRpoRollbackTest extends TestCase
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
            'connector.operations.rpo_rollback.operational_deadline' => 180,
            'connector.operations.rpo_rollback.reconcile_window' => 300,
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

    private function observe(string $a, string $sk, array $hashByRef = [], array $opts = []): void
    {
        $refs = $opts['refs'] ?? [$this->refApp01, $this->refApp02];
        $hashByRef = $hashByRef ?: array_fill_keys($refs, $this->hA);
        $this->sigPost($a, $sk, '/connector/heartbeat', ['observed_at' => time()])->assertOk();
        $body = ['observed_at' => time(), 'appservers' => $this->appservers($opts['up'] ?? [], $refs), 'rest' => [], 'rpo' => $this->rpoEntries($hashByRef, $opts['pu'] ?? [], $refs)];
        if (($opts['withCap'] ?? true)) {
            $body['capabilities'] = [['name' => 'rpo_publish', 'adapter' => 'totvs_x', 'contract_version' => $opts['cver'] ?? 1, 'operations' => ['promote', 'rollback'], 'activation_mode' => $opts['mode'] ?? 'hot']];
        }
        if (! empty($opts['opId'])) { $body['trigger'] = ['type' => 'operation', 'operation_id' => $opts['opId']]; }
        $this->sigPost($a, $sk, '/connector/inventory', $body)->assertOk();
    }
    private function ackP(string $a, string $sk, int $id, string $eid, string $phase): \Illuminate\Testing\TestResponse
    {
        return $this->sigPost($a, $sk, "/connector/operations/{$id}/ack", ['execution_id' => $eid, 'phase' => $phase]);
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
    private function qualify(int $tid, int $artId, string $reason = 'known good'): int { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/qualify", ['artifact_id' => $artId, 'reason' => $reason])->json('data.id'); }
    private function revokeQual(int $qid): \Illuminate\Testing\TestResponse { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/rpo/qualifications/{$qid}/revoke"); }
    private function targetView(int $tid): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/rpo/targets/{$tid}")->json('data'); }

    private function rollback(User $u, int $tid, int $qid, string $reason = 'recuperação hot'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/rollback", ['qualification_id' => $qid, 'reason' => $reason]);
    }
    private function approve(int $id): \Illuminate\Testing\TestResponse { return $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/approve"); }
    private function reconcile(int $id): array { return $this->actingAs($this->userWith(['prosight.operations.rpo.approve']), 'sanctum')->postJson("/api/v1/prosight/operations/{$id}/reconcile")->json('data'); }
    private function show(int $id): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/operations/{$id}")->json('data'); }
    private function forcePast(int $id, string $col, int $s = 5): void { ConnectorOperation::whereKey($id)->update([$col => now()->subSeconds($s)]); }

    /** Setup: 2 AppServers; A(hA) qualificado known_good; target confirmado; target movido p/ B(hB). */
    private function setupRb(int $env, string $a, string $sk): array
    {
        $this->observe($a, $sk); // hA/hA
        $artA = $this->register($env, $this->hA);
        $tid = $this->createTarget($env, [$this->refApp01, $this->refApp02]);
        $this->confirmTarget($tid)->assertOk();      // consistente em hA
        $qA = $this->qualify($tid, $artA);            // known_good A
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB]); // agora em B
        return [$tid, $qA, $artA];
    }
    private function claim(int $env, string $a, string $sk, int $tid, int $qA): array
    {
        $id = $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->assertStatus(201)->json('data.id');
        $this->approve($id)->assertOk();
        $data = $this->sigGet($a, $sk, '/connector/operations/next')->assertOk()->json('data');
        return [$id, $data['execution_id'], $data];
    }

    // ── autoridade do destino ─────────────────────────────────────────────────

    public function test_permission_rollback_required(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        $this->rollback($this->userWith(['prosight.operations.rpo.promote']), $tid, $qA)->assertStatus(403); // promote ≠ rollback
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->assertStatus(201);
    }

    public function test_headline_b_to_known_good_a(): void
    {
        // B → known_good A → rollback → A (agent view identidade sem path + 2 marcadores + success).
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA, $artA] = $this->setupRb($env, $a, $sk);
        [$id, $eid, $view] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->assertSame($this->hA, $view['rpo']['to_hash']);   // destino = known_good A
        $this->assertSame($this->hB, $view['rpo']['from_hash']); // origem = observado atual B
        $this->assertSame('hot', $view['rpo']['activation_mode']);
        $this->assertSame('rpo_rollback', $this->show($id)['op_type']);
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        $this->ackP($a, $sk, $id, $eid, 'effect_started')->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hA], ['opId' => $id]); // recuperou A
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk();
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
        // sucesso → last_successfully_published=A; qualificação known_good PERMANECE (não re-qualifica).
        $tv = $this->targetView($tid);
        $this->assertSame($this->hA, $tv['last_successfully_published']['hash']);
        $this->assertNotNull($tv['last_known_good']);
        $this->assertSame($artA, $tv['last_known_good']['artifact_id']); // known_good A intacto (não re-qualificado)
    }

    public function test_destination_must_be_known_good(): void
    {
        // artefato registered mas NÃO qualificado → não há qualification p/ apontar. Revogar Q → qualification_revoked.
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        $this->revokeQual($qA)->assertOk();
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)
            ->assertStatus(422)->assertJsonFragment(['reasons' => ['qualification_revoked']]);
    }

    public function test_known_good_of_other_target_blocks(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        // qualificação de OUTRO target (contexto errado) → 404 no controller (anti-IDOR/contexto).
        $art2 = $this->register($env, $this->hX);
        $t2 = $this->createTarget($env, ['33333333-aaaa-4bbb-8ccc-333333333333']);
        $q2 = $this->qualify($t2, $art2);
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $q2)->assertStatus(404);
    }

    public function test_already_at_rollback_target_blocks_creation(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hA]); // já em A
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)
            ->assertStatus(422)->assertJsonPath('error', 'already_at_rollback_target');
    }

    public function test_already_at_by_effective_hash_distinct_artifact(): void
    {
        // Q aponta p/ artefato DIFERENTE mas MESMO hash do observado → already_at (decisão por HASH, não id).
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk); // observado em hA
        $this->register($env, $this->hA);            // primeiro artefato de hash hA
        $artA2 = $this->register($env, $this->hA);   // SEGUNDO artefato, MESMO hash hA (identidade adm. distinta)
        $tid = $this->createTarget($env, [$this->refApp01, $this->refApp02]);
        $this->confirmTarget($tid)->assertOk();
        $qA2 = $this->qualify($tid, $artA2);         // qualifica o 2º artefato (hash hA)
        // observed_current_hash (hA) == qualification.hash (hA) → already_at, decidido pelo HASH, não pelo artifact_id.
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA2)
            ->assertStatus(422)->assertJsonPath('error', 'already_at_rollback_target');
    }

    public function test_operator_chooses_historic_known_good(): void
    {
        // Duas known_good válidas (A antiga, X nova). Operador escolhe A explicitamente → permitido.
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        $artX = $this->register($env, $this->hX);
        $this->qualify($tid, $artX); // X mais recente
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->assertStatus(201); // escolheu A (antiga)
    }

    // ── maker-checker / concorrência ──────────────────────────────────────────

    public function test_one_live_per_environment(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->assertStatus(201);
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->assertStatus(409)->assertJsonPath('error', 'operation_in_flight');
    }

    // ── revalidação no dispatch ───────────────────────────────────────────────

    public function test_dispatch_block_qualification_revoked_after_approval(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        $id = $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->json('data.id');
        $this->approve($id)->assertOk();
        $this->revokeQual($qA)->assertOk();                       // revogada antes do claim
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
    }

    public function test_revoked_q1_does_not_migrate_to_q2_same_hash(): void
    {
        // Q1 e Q2 apontam p/ o MESMO A/hash. Aprovado com Q1. Revoga Q1 (Q2 válida) → dispatch bloqueia,
        // NÃO migra p/ Q2. Exige nova operação/aprovação.
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA, $artA] = $this->setupRb($env, $a, $sk);
        $q2 = $this->qualify($tid, $artA, 'segunda qualificação do mesmo A'); // mesmo artifact/hash
        $id = $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->json('data.id');
        $this->approve($id)->assertOk();
        $this->revokeQual($qA)->assertOk();                        // Q1 revogada; Q2 continua válida
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']); // não substituiu por Q2
    }

    public function test_new_known_good_after_approval_does_not_invalidate(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        $id = $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->json('data.id');
        $this->approve($id)->assertOk();
        $artX = $this->register($env, $this->hX);
        $this->qualify($tid, $artX);                               // nova known_good X surge DEPOIS da aprovação
        $eid = $this->sigGet($a, $sk, '/connector/operations/next')->assertOk()->json('data.execution_id'); // ainda dispatcha
        $this->assertNotNull($eid);
    }

    public function test_dispatch_block_from_hash_diverged(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        $id = $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)->json('data.id');
        $this->approve($id)->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hX, $this->refApp02 => $this->hX]); // RPO ativo mudou p/ X
        $this->sigGet($a, $sk, '/connector/operations/next')->assertNoContent();
        $this->assertSame('canceled', $this->show($id)['status']);
    }

    // ── barreira: revogação antes × depois de execution_committed ─────────────

    public function test_revoked_before_commit_blocks_barrier_effect_zero(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->revokeQual($qA)->assertOk();                        // revogada entre claim e commit
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertStatus(409); // barreira NÃO cruzada
        $effect = 0;                                               // agente não aplica
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB], ['opId' => $id]); // continua B
        $this->forcePast($id, 'operational_deadline_at'); $this->show($id); // → indeterminate
        $this->forcePast($id, 'claimed_at', 1000);
        $this->assertSame('reconciled_noop', $this->reconcile($id)['status']);
        $this->assertSame(0, $effect);
    }

    public function test_revoked_after_commit_execution_proceeds(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk(); // barreira cruzada
        $this->revokeQual($qA)->assertOk();                                 // revogada DEPOIS do commit
        $effect = 1;                                                        // execução prossegue
        $this->ackP($a, $sk, $id, $eid, 'effect_started')->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hA], ['opId' => $id]);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'ok', 'phase' => 'post_effect'])->assertOk();
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
        $this->assertSame(1, $effect);
    }

    // ── pré-efeito (agente) ───────────────────────────────────────────────────

    public function test_local_precondition_fail_pre_effect(): void
    {
        // from_hash local diverge OU bytes divergem do SHA qualificado → agente reporta fail pré-efeito → failed.
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->sigPost($a, $sk, "/connector/operations/{$id}/result", ['execution_id' => $eid, 'outcome' => 'fail', 'phase' => 'pre_effect'])->assertOk()->assertJsonPath('status', 'failed');
    }

    // ── distribuídos (comunicação perdida) ───────────────────────────────────

    public function test_distributed_apply_result_lost_success(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        $this->ackP($a, $sk, $id, $eid, 'effect_started')->assertOk();
        $effect = 1;
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hA], ['opId' => $id]); // aplicou A, result perdido
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('indeterminate', $this->show($id)['status']);
        $this->assertSame('reconciled_success', $this->reconcile($id)['status']);
        $this->assertSame(1, $effect);
    }

    public function test_distributed_lost_ack_noop(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB], ['opId' => $id]); // efeito 0, continua B
        $this->forcePast($id, 'operational_deadline_at'); $this->show($id);
        $this->assertSame('reconciling', $this->reconcile($id)['status']);
        $this->forcePast($id, 'execution_committed_at', 1000);
        $this->assertSame('reconciled_noop', $this->reconcile($id)['status']);
    }

    // ── divergências observadas (freeze / humano) ────────────────────────────

    public function test_unexpected_hash_contradicted(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hX], ['opId' => $id]); // hash inesperado
        $this->forcePast($id, 'operational_deadline_at');
        $this->assertSame('contradicted', $this->reconcile($id)['status']);
    }

    public function test_partial_apply_contradicted(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hB], ['opId' => $id]); // A/B parcial
        $this->forcePast($id, 'operational_deadline_at'); $this->forcePast($id, 'execution_committed_at', 1000);
        $out = $this->reconcile($id);
        $this->assertSame('contradicted', $out['status']);
        $this->assertSame('partial_apply', $out['reconciliation_state']);
    }

    public function test_recovery_failed_member_down(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        [$id, $eid] = $this->claim($env, $a, $sk, $tid, $qA);
        $this->ackP($a, $sk, $id, $eid, 'execution_committed')->assertOk();
        $this->observe($a, $sk, [$this->refApp01 => $this->hA, $this->refApp02 => $this->hA], ['opId' => $id, 'up' => [$this->refApp02 => false]]);
        $this->forcePast($id, 'operational_deadline_at'); $this->forcePast($id, 'execution_committed_at', 1000);
        $out = $this->reconcile($id);
        $this->assertSame('contradicted', $out['status']);
        $this->assertSame('recovery_failed', $out['reconciliation_state']);
    }

    // ── activation / hot-only ─────────────────────────────────────────────────

    public function test_activation_mode_not_hot_blocks(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        [$tid, $qA] = $this->setupRb($env, $a, $sk);
        $this->observe($a, $sk, [$this->refApp01 => $this->hB, $this->refApp02 => $this->hB], ['mode' => 'requires_restart']);
        $this->rollback($this->userWith(['prosight.operations.rpo.rollback']), $tid, $qA)
            ->assertStatus(422)->assertJsonFragment(['reasons' => ['activation_mode_not_executable']]);
    }
}
