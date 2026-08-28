<?php

namespace Tests\Feature;

use App\Connector\PatchExecutionService;
use App\Connector\PatchService;
use App\Models\ConnectorWorkspaceLock;
use App\Models\Customer;
use App\Models\EnvEnvironment;
use App\Models\PatchArtifactCandidate;
use App\Models\PatchExecution;
use App\Models\PatchExecutionItem;
use App\Models\PatchInput;
use App\Models\PatchRequest;
use App\Models\Project;
use App\Models\RpoArtifact;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PATCH P2 — MÁQUINA GOVERNADA DE EXECUÇÃO (fixture/simulated; Live unavailable; SEM física TOTVS).
 * Prova a SEMÂNTICA distribuída: FENCING (só o detentor da autoridade cruza o barrier), immutable pin,
 * journal durável por item, at-most-once pós-effect, partial→zero candidate, reconcile CAUSAL (digest sozinho
 * ≠ success), lost-response A-E, release seguro (indeterminate segura o workspace), candidate só 3/3+artifact+causal,
 * ZERO publish C5, ZERO mudança de produção, Live indisponível. Falhas injetadas de forma determinística.
 */
class PatchP2Test extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private int $envA;
    private User $actor;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false, 'connector.nonce_store' => null,
            'connector.patch.executable_modes' => ['simulated', 'live'],
            'connector.patch.allow_fixture' => false,
            'connector.patch.live_ready' => false,
            'connector.patch.transport_lease' => 120,
            'connector.patch.supported_capabilities' => [['name' => 'rpo_patch', 'contract_version' => 1]]]);
        $this->custA = Customer::factory()->create();
        $this->envA = $this->makeEnv($this->custA);
        $this->actor = $this->admin();
    }

    private function envValue(string $k): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (str_starts_with($l, "{$k}=")) { return trim(substr($l, strlen($k) + 1)); }
        }
        return '';
    }

    private function makeEnv(Customer $c): int
    {
        $v = Vault::create(['type' => 'client', 'name' => 'Amb ' . $c->id, 'created_by' => null]);
        DB::table('env_client_vaults')->insert(['customer_id' => $c->id, 'vault_id' => $v->id, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('env_environments')->insertGetId(['customer_id' => $c->id, 'vault_id' => $v->id, 'name' => 'Produção', 'type' => 'prod', 'status' => 'online', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    private function svcExec(): PatchExecutionService { return app(PatchExecutionService::class); }

    /** Cria N inputs (digests distintos) e uma request num workspace, via o builder real do P1. */
    private function mkRequest(string $workspace, int $n = 3, ?string $base = null): PatchRequest
    {
        $env = EnvEnvironment::find($this->envA);
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = PatchInput::create(['environment_id' => $this->envA, 'customer_id' => $this->custA->id,
                'patch_id' => 'PTM-' . $i . '-' . bin2hex(random_bytes(2)), 'digest' => hash('sha256', $workspace . 'i' . $i . bin2hex(random_bytes(3))),
                'classification' => 'test', 'created_by' => $this->actor->id])->id;
        }
        $res = app(PatchService::class)->createRequest($env, [
            'base_rpo_hash' => $base ?: hash('sha256', 'base' . $workspace), 'execution_mode' => 'simulated',
            'workspace_unit_id' => $workspace, 'patch_input_ids' => $ids, 'classification' => 'test',
        ], $this->actor->id);
        $this->assertTrue($res['ok'] ?? false, 'request build failed: ' . json_encode($res));
        return $res['request']->fresh();
    }

    /** Percorre o journal feliz até artifact_verified (SEM enviar result). Retorna a execução. */
    private function runToArtifactVerified(PatchExecution $ex): PatchExecution
    {
        $s = $this->svcExec();
        $this->assertTrue($s->ack($ex, 'base_verified', null)['ok']);
        $this->assertTrue($s->ack($ex->fresh(), 'patch_effect_started', null)['ok']);
        foreach ($ex->items as $it) {
            $this->assertTrue($s->ack($ex->fresh(), 'patch_item_started', $it->batch_order)['ok']);
            $this->assertTrue($s->ack($ex->fresh(), 'patch_item_committed', $it->batch_order)['ok']);
        }
        $this->assertTrue($s->ack($ex->fresh(), 'patch_effect_committed', null)['ok']);
        $this->assertTrue($s->ack($ex->fresh(), 'artifact_verified', null)['ok']);
        return $ex->fresh();
    }

    private function expireLease(PatchExecution $ex): void
    {
        ConnectorWorkspaceLock::whereKey($ex->lock_id)->update(['lease_expires_at' => now()->subMinutes(5)]);
    }

    // ── 1. Dispatch adquire lock FENCED + IMMUTABLE PIN + itens + execution_committed. ──
    public function test_dispatch_acquires_fenced_lock_and_pins(): void
    {
        $req = $this->mkRequest('WS-1', 3);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $this->assertSame(PatchExecution::ST_CLAIMED, $ex->status);
        $this->assertNotNull($ex->execution_committed_at);
        $this->assertSame($req->base_rpo_hash, $ex->base_rpo_hash);   // PIN
        $this->assertSame($req->batch_digest, $ex->batch_digest);     // PIN
        $lock = ConnectorWorkspaceLock::find($ex->lock_id);
        $this->assertSame('active', $lock->status);
        $this->assertSame((int) $lock->fence_token, (int) $ex->fence_token);
        $this->assertSame('patch', $lock->producer);
        $this->assertSame(3, PatchExecutionItem::where('patch_execution_id', $ex->id)->count());
    }

    // ── 2. Um mutável ativo por workspace (cross-producer). Outro workspace livre. ──
    public function test_one_active_execution_per_workspace(): void
    {
        $r1 = $this->mkRequest('WS-A', 2);
        $r2 = $this->mkRequest('WS-A', 2);
        $this->assertTrue($this->svcExec()->dispatch($r1, 'simulated', $this->actor->id)['ok']);
        $blocked = $this->svcExec()->dispatch($r2, 'simulated', $this->actor->id);
        $this->assertFalse($blocked['ok']);
        $this->assertSame('workspace_busy', $blocked['error']);
        // outro workspace → livre
        $r3 = $this->mkRequest('WS-B', 2);
        $this->assertTrue($this->svcExec()->dispatch($r3, 'simulated', $this->actor->id)['ok']);
    }

    // ── 3. FENCING: processo antigo (fence anterior) NÃO atravessa o barrier após perder autoridade. ──
    public function test_fencing_old_process_cannot_cross_barrier(): void
    {
        $r1 = $this->mkRequest('WS-F', 2);
        $e1 = $this->svcExec()->dispatch($r1, 'simulated', $this->actor->id)['execution'];
        $this->assertTrue($this->svcExec()->ack($e1, 'base_verified', null)['ok']); // pré-efeito
        $this->expireLease($e1);                                                     // E1 perde a lease (crash)

        // E2 adquire o MESMO workspace (E1 era pré-efeito → reapável) com fence maior.
        $r2 = $this->mkRequest('WS-F', 2);
        $e2 = $this->svcExec()->dispatch($r2, 'simulated', $this->actor->id)['execution'];
        $this->assertGreaterThan((int) $e1->fence_token, (int) $e2->fence_token);

        // E1 (processo antigo) tenta cruzar o barrier → FENCED OUT.
        $r = $this->svcExec()->ack($e1->fresh(), 'patch_effect_started', null);
        $this->assertFalse($r['ok']);
        $this->assertSame('fenced_out', $r['error']);
        // E2 (detentor atual) cruza normalmente.
        $this->assertTrue($this->svcExec()->ack($e2->fresh(), 'base_verified', null)['ok']);
        $this->assertTrue($this->svcExec()->ack($e2->fresh(), 'patch_effect_started', null)['ok']);
    }

    // ── 4. Base é provada ANTES do efeito: barrier bloqueado sem base_verified. ──
    public function test_base_gate_before_barrier(): void
    {
        $req = $this->mkRequest('WS-BG', 2);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $r = $this->svcExec()->ack($ex, 'patch_effect_started', null); // sem base_verified
        $this->assertFalse($r['ok']);
        $this->assertSame('base_not_verified', $r['error']);
        $this->assertSame(0, PatchArtifactCandidate::count());
    }

    // ── 5. Lote completo 3/3 + artifact_verified + digest → CANDIDATE (provenance congelada, SIMULADO). ──
    public function test_full_batch_produces_candidate(): void
    {
        $req = $this->mkRequest('WS-OK', 3);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $ex = $this->runToArtifactVerified($ex);
        $digest = hash('sha256', 'candidate-ok');
        $res = $this->svcExec()->result($ex, 'success', $digest, $this->actor->id);
        $this->assertTrue($res['ok']);
        $this->assertSame(PatchExecution::ST_CANDIDATE, $res['execution']->status);
        $cand = PatchArtifactCandidate::where('patch_execution_id', $ex->id)->first();
        $this->assertNotNull($cand);
        $this->assertSame($digest, $cand->candidate_digest);
        $this->assertSame('none', $cand->handoff_status);            // NÃO registrado no C5
        $this->assertTrue($cand->provenance['simulated']);           // SIMULADO explícito
        $this->assertSame($ex->execution_id, $cand->provenance['execution_id']);
        $this->assertSame('WS-OK', $cand->provenance['workspace_unit_id']);
        $this->assertCount(3, $cand->provenance['item_digests']);
        // lock liberado (terminal comprovado)
        $this->assertSame('released', ConnectorWorkspaceLock::find($ex->lock_id)->status);
    }

    // ── 6. Lote PARCIAL (PTM1 commit, PTM2 falha, PTM3 nunca inicia) → PARTIAL → ZERO candidate; exige re-seed. ──
    public function test_partial_batch_zero_candidate(): void
    {
        $req = $this->mkRequest('WS-PART', 3);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $s = $this->svcExec();
        $s->ack($ex, 'base_verified', null);
        $s->ack($ex->fresh(), 'patch_effect_started', null);
        $s->ack($ex->fresh(), 'patch_item_started', 1);
        $s->ack($ex->fresh(), 'patch_item_committed', 1); // PTM1 commit
        $s->ack($ex->fresh(), 'patch_item_started', 2);   // PTM2 inicia e falha (nunca commita)
        $res = $s->result($ex->fresh(), 'partial', null, $this->actor->id);
        $this->assertTrue($res['ok']);
        $this->assertSame(PatchExecution::ST_PARTIAL, $res['execution']->status);
        $this->assertSame(1, (int) $res['execution']->applied_items);
        $this->assertSame(0, PatchArtifactCandidate::count());       // ZERO candidate
        $this->assertSame('released', ConnectorWorkspaceLock::find($ex->lock_id)->status); // partial comprovado libera
        // Recovery: NOVA execução (re-seed base) — não continua do PTM2.
        $this->assertTrue($this->svcExec()->dispatch($this->mkRequest('WS-PART', 3), 'simulated', $this->actor->id)['ok']);
    }

    // ── 7. At-most-once: sem retry automático pós-effect; result após terminal → already_terminal. ──
    public function test_at_most_once_no_retry_after_effect(): void
    {
        $req = $this->mkRequest('WS-AMO', 2);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $ex = $this->runToArtifactVerified($ex);
        $this->assertTrue($this->svcExec()->result($ex, 'success', hash('sha256', 'x'), $this->actor->id)['ok']);
        // segunda tentativa (lost-ACK falso positivo) → NÃO reaplica.
        $again = $this->svcExec()->result($ex->fresh(), 'success', hash('sha256', 'x'), $this->actor->id);
        $this->assertFalse($again['ok']);
        $this->assertSame('already_terminal', $again['error']);
        $this->assertSame(1, PatchArtifactCandidate::where('patch_execution_id', $ex->id)->count()); // efeito único
    }

    // ── 8A. Lost antes do barrier → reconcile → failed (efeito 0). ──
    public function test_gate_A_lost_before_barrier(): void
    {
        $req = $this->mkRequest('WS-A8', 2);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $this->svcExec()->ack($ex, 'base_verified', null); // ainda pré-efeito
        $res = $this->svcExec()->reconcile($ex->fresh(), null, $this->actor->id);
        $this->assertSame('failed', $res['outcome']);
        $this->assertSame(0, PatchArtifactCandidate::count());
        $this->assertSame('released', ConnectorWorkspaceLock::find($ex->lock_id)->status);
    }

    // ── 8B. Lost do ACK execution_committed → sem efeito duplicado (re-dispatch bloqueado). ──
    public function test_gate_B_no_duplicate_effect(): void
    {
        $req = $this->mkRequest('WS-B8', 2);
        $this->assertTrue($this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['ok']);
        $dup = $this->svcExec()->dispatch($req->fresh(), 'simulated', $this->actor->id); // Minutor "não recebeu" → tenta de novo
        $this->assertFalse($dup['ok']);
        $this->assertSame('execution_in_progress', $dup['error']);
        $this->assertSame(1, PatchExecution::where('patch_request_id', $req->id)->count()); // efeito único
    }

    // ── 8C. Lost após 1 PTM → reconcile → partial (NUNCA retry). ──
    public function test_gate_C_lost_after_one_ptm_partial(): void
    {
        $req = $this->mkRequest('WS-C8', 3);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $s = $this->svcExec();
        $s->ack($ex, 'base_verified', null);
        $s->ack($ex->fresh(), 'patch_effect_started', null);
        $s->ack($ex->fresh(), 'patch_item_started', 1);
        $s->ack($ex->fresh(), 'patch_item_committed', 1);
        $res = $s->reconcile($ex->fresh(), null, $this->actor->id); // resposta perdida
        $this->assertSame('partial', $res['outcome']);
        $this->assertSame(0, PatchArtifactCandidate::count());
    }

    // ── 8D. Lost após lote completo + artifact_verified + digest observado causal → candidate (efeito=1). ──
    public function test_gate_D_lost_after_full_batch_causal_candidate(): void
    {
        $req = $this->mkRequest('WS-D8', 3);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $ex = $this->runToArtifactVerified($ex); // efeito completo, sem result (resposta perdida)
        $digest = hash('sha256', 'observed-causal');
        $res = $this->svcExec()->reconcile($ex, $digest, $this->actor->id);
        $this->assertSame('candidate', $res['outcome']);
        $cand = PatchArtifactCandidate::where('patch_execution_id', $ex->id)->first();
        $this->assertSame($digest, $cand->candidate_digest);
        $this->assertSame('causal_success', $res['execution']->reconciliation_state);
        $this->assertSame(1, PatchArtifactCandidate::where('patch_execution_id', $ex->id)->count());
    }

    // ── 8E. Restart do connector/processo: mesma execução NÃO reaplica efeito (journal durável + terminal). ──
    public function test_gate_E_restart_does_not_reapply(): void
    {
        $req = $this->mkRequest('WS-E8', 2);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $ex = $this->runToArtifactVerified($ex);
        $this->svcExec()->result($ex, 'success', hash('sha256', 'e'), $this->actor->id);
        // "restart": nova instância do serviço + re-ack da MESMA execução → terminal, sem novo efeito.
        app()->forgetInstance(PatchExecutionService::class);
        $r = app(PatchExecutionService::class)->ack($ex->fresh(), 'patch_effect_started', null);
        $this->assertFalse($r['ok']);
        $this->assertSame('already_terminal', $r['error']);
        $this->assertSame(1, PatchArtifactCandidate::where('patch_execution_id', $ex->id)->count());
    }

    // ── 9. CAUSAL: mesmo digest SEM causalidade (sem artifact_verified) NÃO vira success. ──
    public function test_causal_same_digest_without_causality_not_success(): void
    {
        $req = $this->mkRequest('WS-CAU', 3);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $s = $this->svcExec();
        $s->ack($ex, 'base_verified', null);
        $s->ack($ex->fresh(), 'patch_effect_started', null);
        // efeito iniciado, NENHUM item committed, SEM artifact_verified
        $digest = hash('sha256', 'coincidental');
        $res = $s->reconcile($ex->fresh(), $digest, $this->actor->id); // digest válido mas sem causalidade
        $this->assertNotSame('candidate', $res['outcome']);            // NÃO é success
        $this->assertSame(0, PatchArtifactCandidate::count());          // ZERO candidate
        $this->assertSame(PatchExecution::ST_INDETERMINATE, $res['execution']->status);
    }

    // ── 10. Indeterminate SEGURA o workspace: nova execução bloqueada até reconcile/resolução. ──
    public function test_indeterminate_holds_workspace(): void
    {
        $req = $this->mkRequest('WS-IND', 3);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $s = $this->svcExec();
        $s->ack($ex, 'base_verified', null);
        $s->ack($ex->fresh(), 'patch_effect_started', null); // mid-efeito
        $this->expireLease($ex);                              // crash mid-efeito

        // Nova execução no MESMO workspace → bloqueada (indeterminate segura).
        $blocked = $this->svcExec()->dispatch($this->mkRequest('WS-IND', 3), 'simulated', $this->actor->id);
        $this->assertFalse($blocked['ok']);
        $this->assertSame('workspace_indeterminate', $blocked['error']);
        $lock = ConnectorWorkspaceLock::find($ex->lock_id);
        $this->assertTrue((bool) $lock->reconcile_required);
        $this->assertSame('active', $lock->status);           // NÃO liberado por timeout

        // Reconcile (0 itens committed, sem evidência conclusiva) → INDETERMINATE — AINDA segura o workspace.
        $this->svcExec()->reconcile($ex->fresh(), null, $this->actor->id);
        $stillBlocked = $this->svcExec()->dispatch($this->mkRequest('WS-IND', 3), 'simulated', $this->actor->id);
        $this->assertFalse($stillBlocked['ok']);
        $this->assertSame('workspace_indeterminate', $stillBlocked['error']);

        // Só RESOLVE explícito (investigação/re-seed do operador) devolve a autoridade.
        $this->assertTrue($this->svcExec()->resolve($ex->fresh(), $this->actor->id)['ok']);
        $this->assertTrue($this->svcExec()->dispatch($this->mkRequest('WS-IND', 3), 'simulated', $this->actor->id)['ok']);
    }

    // ── 11. Release seguro por resultado; nunca em timeout de transporte (coberto em 7/10). Terminais. ──
    public function test_release_rules_terminals(): void
    {
        // failed pré-efeito libera
        $req = $this->mkRequest('WS-REL', 2);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $this->svcExec()->result($ex, 'failed', null, $this->actor->id);
        $this->assertSame(PatchExecution::ST_FAILED, $ex->fresh()->status);
        $this->assertSame('released', ConnectorWorkspaceLock::find($ex->lock_id)->status);
    }

    // ── 12. ZERO publish C5 / ZERO produção: nenhum RpoArtifact, candidate handoff none. ──
    public function test_zero_c5_publish_and_no_production_change(): void
    {
        $before = RpoArtifact::count();
        $req = $this->mkRequest('WS-C5', 3);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $ex = $this->runToArtifactVerified($ex);
        $this->svcExec()->result($ex, 'success', hash('sha256', 'z'), $this->actor->id);
        $this->assertSame($before, RpoArtifact::count());               // NENHUM artefato C5
        $this->assertSame(0, DB::table('rpo_targets')->where('environment_id', $this->envA)->count());
        $cand = PatchArtifactCandidate::where('patch_execution_id', $ex->id)->first();
        $this->assertSame('none', $cand->handoff_status);               // não promove/qualifica/publica
        $this->assertNull($cand->rpo_artifact_id);
    }

    // ── 13. Live indisponível (sem física TOTVS): execute mode=live → 422 live_unavailable. ──
    public function test_live_execution_unavailable(): void
    {
        // request live só é criável se executable_modes inclui live; mas execução BLOQUEIA.
        $env = EnvEnvironment::find($this->envA);
        $ids = [PatchInput::create(['environment_id' => $this->envA, 'customer_id' => $this->custA->id, 'patch_id' => 'PTM-L', 'digest' => hash('sha256', 'l'), 'created_by' => $this->actor->id])->id];
        $req = app(PatchService::class)->createRequest($env, ['base_rpo_hash' => hash('sha256', 'bl'), 'execution_mode' => 'live', 'workspace_unit_id' => 'WS-L', 'patch_input_ids' => $ids], $this->actor->id)['request'];
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/patch/requests/{$req->id}/execute")
            ->assertStatus(422)->assertJson(['error' => 'live_unavailable']);
        $this->assertSame(0, PatchExecution::where('patch_request_id', $req->id)->count());
    }

    // ── 14. Permissão própria patch.execute + anti-IDOR no execute/reconcile/show. ──
    public function test_permission_and_idor_on_execution(): void
    {
        $req = $this->mkRequest('WS-PERM', 2);
        // sem patch.execute (só view) → 403
        $viewer = User::factory()->create(['type' => 'consultor', 'extra_permissions' => ['prosight.operations.patch.view']]);
        $p = Project::factory()->create(['customer_id' => $this->custA->id]);
        DB::table('project_consultants')->insert(['project_id' => $p->id, 'user_id' => $viewer->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/prosight/patch/requests/{$req->id}/execute")->assertStatus(403);
        // IDOR: outro cliente não enxerga a execução
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $custB = Customer::factory()->create();
        $intruder = User::factory()->create(['type' => 'consultor', 'extra_permissions' => ['prosight.operations.patch.view', 'prosight.operations.patch.execute']]);
        $pb = Project::factory()->create(['customer_id' => $custB->id]);
        DB::table('project_consultants')->insert(['project_id' => $pb->id, 'user_id' => $intruder->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($intruder, 'sanctum')->getJson("/api/v1/prosight/patch/executions/{$ex->id}")->assertStatus(404);
        $this->actingAs($intruder, 'sanctum')->postJson("/api/v1/prosight/patch/executions/{$ex->id}/reconcile")->assertStatus(404);
    }

    // ── 15. Labels HONESTOS: is_simulated true, is_registered/is_published false, nunca "aplicado/publicado". ──
    public function test_honest_labels_simulated(): void
    {
        $req = $this->mkRequest('WS-LBL', 2);
        $ex = $this->svcExec()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $ex = $this->runToArtifactVerified($ex);
        $this->svcExec()->result($ex, 'success', hash('sha256', 'lbl'), $this->actor->id);
        $j = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/patch/executions/{$ex->id}")->assertOk()->json('data.execution');
        $this->assertTrue($j['is_simulated']);
        $this->assertFalse($j['is_registered']);
        $this->assertFalse($j['is_published']);
        $this->assertStringContainsString('SIMULADO', $j['label']);
        $this->assertStringContainsString('ainda não registrado', $j['label']);
    }
}
