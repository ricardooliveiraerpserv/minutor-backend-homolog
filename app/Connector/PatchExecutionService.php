<?php

namespace App\Connector;

use App\Models\ConnectorEvent;
use App\Models\ConnectorWorkspaceLock;
use App\Models\PatchArtifactCandidate;
use App\Models\PatchExecution;
use App\Models\PatchExecutionItem;
use App\Models\PatchRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Connector\RpoRegistryService;

/**
 * PATCH P2 — máquina GOVERNADA de execução (fixture/simulated; Live unavailable). Prova a SEMÂNTICA, não a
 * física TOTVS: fencing do workspace (só o detentor da autoridade atravessa o barrier), immutable pin, journal
 * durável por item, at-most-once pós-effect, partial→zero candidate, reconcile CAUSAL (digest sozinho ≠ success),
 * release seguro (indeterminate segura o workspace). NÃO promove/qualifica/publica (C5 intocado). NÃO altera C6.
 */
class PatchExecutionService
{
    public function __construct(private RpoRegistryService $rpo)
    {
    }

    private function lease(): int { return (int) config('connector.patch.transport_lease', 120); }

    private function emit(int $envId, string $type, ?string $detail, array $meta): void
    {
        ConnectorEvent::create(['environment_id' => $envId, 'appserver_ref' => null, 'event_type' => $type,
            'outcome' => 'info', 'detail' => $detail, 'meta' => $meta, 'occurred_at' => now()]);
    }

    // ── FENCING: adquire o lock do workspace. Só 1 execução MUTÁVEL ativa por workspace (cross-producer). ──
    // Lease expirado PRÉ-efeito → reapável (novo fence). Mid-efeito → indeterminate (segura; exige reconcile).
    private function acquireLock(int $envId, string $workspaceUnit, string $executionId): array
    {
        return DB::transaction(function () use ($envId, $workspaceUnit, $executionId) {
            $active = ConnectorWorkspaceLock::where('environment_id', $envId)->where('workspace_unit_id', $workspaceUnit)
                ->where('status', ConnectorWorkspaceLock::ST_ACTIVE)->lockForUpdate()->first();
            $now = now();
            if ($active) {
                if ($active->reconcile_required) {
                    return ['ok' => false, 'error' => 'workspace_indeterminate']; // execução anterior indeterminada segura o workspace
                }
                if ($active->lease_expires_at && $active->lease_expires_at->gt($now)) {
                    return ['ok' => false, 'error' => 'workspace_busy']; // detentor vivo (lease válida)
                }
                // lease expirada: só reapável se NÃO cruzou o barrier (pré-efeito). Mid-efeito → indeterminate (segura).
                $holder = PatchExecution::where('execution_id', $active->execution_ref)->first();
                if ($holder && $holder->patch_effect_started_at && ! in_array($holder->status, PatchExecution::TERMINAL, true)) {
                    $active->update(['reconcile_required' => true]); // efeito iniciado + lease morta → segura o workspace
                    return ['ok' => false, 'error' => 'workspace_indeterminate'];
                }
                $active->update(['status' => ConnectorWorkspaceLock::ST_RELEASED, 'released_at' => $now]); // reap pré-efeito
            }
            $maxFence = (int) ConnectorWorkspaceLock::where('environment_id', $envId)->where('workspace_unit_id', $workspaceUnit)->max('fence_token');
            try {
                $lock = ConnectorWorkspaceLock::create([
                    'environment_id' => $envId, 'workspace_unit_id' => $workspaceUnit, 'producer' => ConnectorWorkspaceLock::PRODUCER_PATCH,
                    'execution_ref' => $executionId, 'status' => ConnectorWorkspaceLock::ST_ACTIVE, 'fence_token' => $maxFence + 1,
                    'acquired_at' => $now, 'lease_expires_at' => $now->copy()->addSeconds($this->lease()),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                return ['ok' => false, 'error' => 'workspace_busy']; // corrida: índice parcial UNIQUE ACTIVE fail-closed
            }
            return ['ok' => true, 'lock' => $lock];
        });
    }

    /** Só o detentor ATUAL da autoridade (fence == lock ativo + lease válido) atravessa o barrier. */
    private function fenceValid(PatchExecution $ex): bool
    {
        $lock = ConnectorWorkspaceLock::where('environment_id', $this->envIdOf($ex))->where('workspace_unit_id', $ex->workspace_unit_id)
            ->where('status', ConnectorWorkspaceLock::ST_ACTIVE)->first();
        return $lock && (int) $lock->fence_token === (int) $ex->fence_token
            && $lock->lease_expires_at && $lock->lease_expires_at->gt(now());
    }

    private function envIdOf(PatchExecution $ex): int
    {
        return (int) PatchRequest::whereKey($ex->patch_request_id)->value('environment_id');
    }

    private function extendLease(PatchExecution $ex): void
    {
        ConnectorWorkspaceLock::whereKey($ex->lock_id)->where('status', ConnectorWorkspaceLock::ST_ACTIVE)
            ->update(['lease_expires_at' => now()->addSeconds($this->lease())]);
    }

    private function releaseLock(PatchExecution $ex, bool $reconcileRequired = false): void
    {
        ConnectorWorkspaceLock::whereKey($ex->lock_id)->where('status', ConnectorWorkspaceLock::ST_ACTIVE)
            ->update($reconcileRequired
                ? ['reconcile_required' => true]
                : ['status' => ConnectorWorkspaceLock::ST_RELEASED, 'released_at' => now()]);
    }

    // ── DISPATCH: adquire lock fenced + cria execução com IMMUTABLE PIN + itens. execution_committed. ──
    public function dispatch(PatchRequest $req, string $adapter, int $userId): array
    {
        if ($req->status === PatchRequest::ST_CANCELED) { return ['ok' => false, 'error' => 'request_canceled', 'status' => 409]; }
        if ($req->executions()->whereNotIn('status', PatchExecution::TERMINAL)->exists()) {
            return ['ok' => false, 'error' => 'execution_in_progress', 'status' => 409];
        }
        if (! $req->workspace_unit_id) { return ['ok' => false, 'error' => 'workspace_unit_required', 'status' => 422]; }

        $executionId = (string) Str::uuid();
        $acq = $this->acquireLock((int) $req->environment_id, $req->workspace_unit_id, $executionId);
        if (! ($acq['ok'] ?? false)) { return ['ok' => false, 'error' => $acq['error'], 'status' => 409]; }
        $lock = $acq['lock'];

        $ex = DB::transaction(function () use ($req, $executionId, $adapter, $lock) {
            $e = PatchExecution::create([
                'patch_request_id' => $req->id, 'execution_id' => $executionId, 'workspace_unit_id' => $req->workspace_unit_id,
                'execution_mode' => $req->execution_mode, 'adapter' => $adapter, 'status' => PatchExecution::ST_CLAIMED,
                'fence_token' => $lock->fence_token, 'lock_id' => $lock->id,
                'base_rpo_hash' => $req->base_rpo_hash, 'batch_digest' => $req->batch_digest, // IMMUTABLE PIN
                'capability_adapter_version' => $adapter . '@sim', 'execution_committed_at' => now(),
                'deadline_at' => now()->addSeconds((int) config('connector.patch.operational_deadline', 600)),
            ]);
            foreach ($req->items()->get() as $it) {
                PatchExecutionItem::create(['patch_execution_id' => $e->id, 'batch_order' => $it->batch_order,
                    'patch_input_id' => $it->patch_input_id, 'item_digest' => $it->item_digest, 'status' => 'pending']);
            }
            return $e;
        });
        $req->update(['status' => PatchRequest::ST_EXECUTING]);
        $this->emit((int) $req->environment_id, 'patch.execution_committed', 'Execução Patch iniciada (SIMULADO)', ['request_id' => $req->id, 'execution_id' => $executionId, 'fence' => $lock->fence_token, 'workspace' => $req->workspace_unit_id]);
        return ['ok' => true, 'execution' => $ex];
    }

    // ── ACK de marcadores (agente). Barrier = patch_effect_started com FENCING. ──
    public function ack(PatchExecution $ex, string $phase, ?int $itemOrder): array
    {
        if (in_array($ex->status, PatchExecution::TERMINAL, true)) { return ['ok' => false, 'error' => 'already_terminal', 'status' => 409]; }
        $env = $this->envIdOf($ex);

        switch ($phase) {
            case 'base_verified':
                $ex->update(['status' => PatchExecution::ST_BASE_VERIFIED, 'base_verified_at' => now()]);
                $this->extendLease($ex);
                break;
            case 'patch_effect_started': // BARRIER — só o detentor atual da autoridade cruza (fencing).
                if (! $this->fenceValid($ex)) { return ['ok' => false, 'error' => 'fenced_out', 'status' => 409]; }
                if (! $ex->base_verified_at) { return ['ok' => false, 'error' => 'base_not_verified', 'status' => 409]; }
                $ex->update(['status' => PatchExecution::ST_PATCH_EFFECT_STARTED, 'patch_effect_started_at' => now()]);
                $this->extendLease($ex);
                break;
            case 'patch_item_started':
                if (! $ex->patch_effect_started_at) { return ['ok' => false, 'error' => 'barrier_not_crossed', 'status' => 409]; }
                PatchExecutionItem::where('patch_execution_id', $ex->id)->where('batch_order', $itemOrder)
                    ->update(['status' => 'started', 'started_at' => now()]);
                $this->extendLease($ex);
                break;
            case 'patch_item_committed':
                PatchExecutionItem::where('patch_execution_id', $ex->id)->where('batch_order', $itemOrder)
                    ->update(['status' => 'committed', 'committed_at' => now()]);
                $this->extendLease($ex);
                break;
            case 'patch_effect_committed':
                $pending = PatchExecutionItem::where('patch_execution_id', $ex->id)->where('status', '!=', 'committed')->count();
                if ($pending > 0) { return ['ok' => false, 'error' => 'items_not_all_committed', 'status' => 409]; }
                $ex->update(['status' => PatchExecution::ST_PATCH_EFFECT_COMMITTED, 'patch_effect_committed_at' => now()]);
                $this->extendLease($ex);
                break;
            case 'artifact_verified':
                if (! $ex->patch_effect_committed_at) { return ['ok' => false, 'error' => 'effect_not_committed', 'status' => 409]; }
                $ex->update(['status' => PatchExecution::ST_ARTIFACT_VERIFIED, 'artifact_verified_at' => now()]);
                $this->extendLease($ex);
                break;
            default:
                return ['ok' => false, 'error' => 'invalid_phase', 'status' => 422];
        }
        $this->emit($env, 'patch.' . $phase, null, ['execution_id' => $ex->execution_id, 'item' => $itemOrder]);
        return ['ok' => true, 'execution' => $ex->fresh()];
    }

    // ── RESULT (agente). Sucesso exige artifact_verified + candidate_digest (CAUSAL). partial/failed → zero candidate. ──
    public function result(PatchExecution $ex, string $outcome, ?string $candidateDigest, int $userId): array
    {
        if (in_array($ex->status, PatchExecution::TERMINAL, true)) { return ['ok' => false, 'error' => 'already_terminal', 'status' => 409]; }
        $env = $this->envIdOf($ex);

        if ($outcome === 'success') {
            if (! $ex->artifact_verified_at || ! $candidateDigest || ! preg_match('/^[0-9a-f]{64}$/i', $candidateDigest)) {
                return ['ok' => false, 'error' => 'success_without_verified_artifact', 'status' => 409]; // causal: sem prova → sem candidate
            }
            $cand = $this->makeCandidate($ex, $candidateDigest, $userId);
            $ex->update(['status' => PatchExecution::ST_CANDIDATE, 'outcome' => 'candidate', 'candidate_digest' => strtolower($candidateDigest), 'finished_at' => now(), 'applied_items' => PatchExecutionItem::where('patch_execution_id', $ex->id)->where('status', 'committed')->count()]);
            $this->releaseLock($ex); // terminal comprovado → libera
            $this->emit($env, 'patch.candidate', 'Artefato candidato Patch (SIMULADO)', ['execution_id' => $ex->execution_id, 'candidate_id' => $cand->id, 'digest' => substr($candidateDigest, 0, 12)]);
            return ['ok' => true, 'execution' => $ex->fresh(), 'candidate' => $cand];
        }
        // failed | partial — zero candidate. partial exige efeito iniciado com item(s) committed.
        $committed = PatchExecutionItem::where('patch_execution_id', $ex->id)->where('status', 'committed')->count();
        $st = ($outcome === 'partial' || ($ex->patch_effect_started_at && $committed > 0 && $committed < PatchExecutionItem::where('patch_execution_id', $ex->id)->count()))
            ? PatchExecution::ST_PARTIAL : PatchExecution::ST_FAILED;
        $ex->update(['status' => $st, 'outcome' => $st, 'applied_items' => $committed, 'finished_at' => now(), 'error' => $outcome]);
        $this->releaseLock($ex); // failed/partial comprovado → libera
        $this->emit($env, 'patch.' . $st, null, ['execution_id' => $ex->execution_id, 'applied_items' => $committed]);
        return ['ok' => true, 'execution' => $ex->fresh(), 'candidate' => null];
    }

    // ── RECONCILE (perda de ACK/resposta). CAUSAL: digest sozinho ≠ success. Indeterminate SEGURA o workspace. ──
    public function reconcile(PatchExecution $ex, ?string $observedCandidateDigest, int $userId): array
    {
        if (in_array($ex->status, PatchExecution::TERMINAL, true)) { return ['ok' => true, 'execution' => $ex, 'outcome' => $ex->status]; }
        $env = $this->envIdOf($ex);
        $total = PatchExecutionItem::where('patch_execution_id', $ex->id)->count();
        $committed = PatchExecutionItem::where('patch_execution_id', $ex->id)->where('status', 'committed')->count();

        // A) barrier NÃO cruzado → efeito 0 → failed (nenhum efeito líquido).
        if (! $ex->patch_effect_started_at) {
            $ex->update(['status' => PatchExecution::ST_FAILED, 'outcome' => 'failed', 'reconciliation_state' => 'no_effect', 'finished_at' => now()]);
            $this->releaseLock($ex);
            return $this->recRet($env, $ex, 'failed');
        }
        // D) lote completo + artifact_verified + digest observado CORRELACIONADO a ESTA execução → candidate (efeito=1).
        if ($ex->artifact_verified_at && $committed === $total && $total > 0 && $observedCandidateDigest && preg_match('/^[0-9a-f]{64}$/i', $observedCandidateDigest)) {
            $cand = $this->makeCandidate($ex, $observedCandidateDigest, $userId);
            $ex->update(['status' => PatchExecution::ST_CANDIDATE, 'outcome' => 'candidate', 'candidate_digest' => strtolower($observedCandidateDigest), 'reconciliation_state' => 'causal_success', 'applied_items' => $committed, 'finished_at' => now()]);
            $this->releaseLock($ex);
            return $this->recRet($env, $ex, 'candidate');
        }
        // C) efeito iniciado, item(s) committed mas não completo/verificado → partial (zero candidate).
        if ($committed > 0 && ($committed < $total || ! $ex->artifact_verified_at)) {
            $ex->update(['status' => PatchExecution::ST_PARTIAL, 'outcome' => 'partial', 'reconciliation_state' => 'partial_effect', 'applied_items' => $committed, 'finished_at' => now()]);
            $this->releaseLock($ex);
            return $this->recRet($env, $ex, 'partial');
        }
        // Caso contrário (barrier cruzado, sem evidência conclusiva OU digest sem causalidade) → INDETERMINATE.
        // Segura o workspace: reconcile_required (não devolve autoridade até resolução/re-seed).
        $ex->update(['status' => PatchExecution::ST_INDETERMINATE, 'outcome' => 'indeterminate', 'reconciliation_state' => 'indeterminate', 'finished_at' => now()]);
        $this->releaseLock($ex, reconcileRequired: true);
        return $this->recRet($env, $ex, 'indeterminate');
    }

    // ── RESOLVE (operador). Uma execução INDETERMINATE só devolve o workspace após resolução explícita ──
    //    (investigação/re-seed manual). Reconcile sozinho NÃO libera indeterminate (spec #10).
    public function resolve(PatchExecution $ex, int $userId): array
    {
        if ($ex->status !== PatchExecution::ST_INDETERMINATE) {
            return ['ok' => false, 'error' => 'not_indeterminate', 'status' => 409];
        }
        $ex->update(['reconciliation_state' => 'resolved']);
        $this->releaseLock($ex); // agora sim devolve a autoridade sobre o workspace
        $this->emit($this->envIdOf($ex), 'patch.resolved', 'Indeterminado resolvido pelo operador (SIMULADO)', ['execution_id' => $ex->execution_id]);
        return ['ok' => true, 'execution' => $ex->fresh()];
    }

    // ── P3 — HANDOFF GOVERNADO ao registry C5. Espelha o C6: quem REGISTRA é o RpoRegistryService (autoridade C5). ──
    //    Só candidate TERMINAL válido. failed/partial/indeterminate/contradicted → nunca chegam aqui (sem candidate row).
    //    Idempotente (lockForUpdate + guard REGISTERED). NÃO qualifica/promove/publica. Boundary: termina em C5 REGISTERED.
    public function handoff(PatchArtifactCandidate $cand, int $userId): array
    {
        return DB::transaction(function () use ($cand, $userId) {
            $cand = PatchArtifactCandidate::whereKey($cand->id)->lockForUpdate()->first();
            if ($cand->handoff_status === PatchArtifactCandidate::HANDOFF_REGISTERED) {
                return ['ok' => false, 'error' => 'already_registered', 'status' => 409, 'rpo_artifact_id' => $cand->rpo_artifact_id];
            }
            // A execução ligada precisa estar em ST_CANDIDATE (só candidate terminal válido registra).
            $ex = PatchExecution::find($cand->patch_execution_id);
            if (! $ex || $ex->status !== PatchExecution::ST_CANDIDATE) {
                return ['ok' => false, 'error' => 'candidate_not_registerable', 'status' => 409];
            }
            // Proveniência COMPACTA (≤300, zero bytes/path/PTM). Estrutura completa fica na candidate (imutável, linkada).
            $p = $cand->provenance ?? [];
            $prov = 'PATCH-SIM producer=patch exec=' . substr((string) ($p['execution_id'] ?? ''), 0, 12)
                . ' base=' . substr((string) $cand->base_rpo_digest, 0, 12)
                . ' batch=' . substr((string) $cand->batch_digest, 0, 12)
                . ' cap=' . mb_substr((string) $cand->capability_adapter_version, 0, 40) . ' simulated=1';
            $compat = ['source' => 'patch', 'producer' => 'patch', 'simulated' => true, 'appserver_versions' => []];

            // Marca intenção (auditável) ANTES do C5.
            $cand->update(['handoff_status' => PatchArtifactCandidate::HANDOFF_REQUESTED]);
            $this->emit((int) $cand->environment_id, 'patch.handoff_requested', 'Artefato Patch enviado ao registry C5 (SIMULADO)', [
                'candidate_id' => $cand->id, 'digest' => substr((string) $cand->candidate_digest, 0, 12), 'execution_id' => $p['execution_id'] ?? null,
            ]);

            // Autoridade do C5: register no RpoRegistryService. NUNCA promove/qualifica.
            $res = $this->rpo->register((int) $cand->environment_id, $cand->customer_id, [
                'hash' => $cand->candidate_digest, 'version' => null,
                'provenance' => $prov, 'compatibility' => $compat,
                'classification' => $cand->classification, 'source_identity' => null, // NUNCA path/bytes
            ], $userId);
            if (! ($res['ok'] ?? false)) {
                return ['ok' => false, 'error' => $res['error'] ?? 'register_failed', 'status' => 422]; // fica 'requested' (tentável)
            }
            $cand->update(['handoff_status' => PatchArtifactCandidate::HANDOFF_REGISTERED, 'rpo_artifact_id' => $res['artifact']->id]);
            $this->emit((int) $cand->environment_id, 'patch.registered_c5', 'Artefato Patch registrado no C5 — ainda não qualificado (SIMULADO)', [
                'candidate_id' => $cand->id, 'rpo_artifact_id' => $res['artifact']->id,
            ]);
            return ['ok' => true, 'rpo_artifact_id' => $res['artifact']->id, 'candidate' => $cand->fresh()];
        });
    }

    private function recRet(int $env, PatchExecution $ex, string $outcome): array
    {
        $this->emit($env, 'patch.reconciled', 'Reconciliação Patch (SIMULADO)', ['execution_id' => $ex->execution_id, 'outcome' => $outcome]);
        return ['ok' => true, 'execution' => $ex->fresh(), 'outcome' => $outcome];
    }

    private function makeCandidate(PatchExecution $ex, string $digest, int $userId): PatchArtifactCandidate
    {
        $req = PatchRequest::find($ex->patch_request_id);
        return PatchArtifactCandidate::create([
            'patch_execution_id' => $ex->id, 'patch_request_id' => $ex->patch_request_id,
            'environment_id' => $req->environment_id, 'customer_id' => $req->customer_id,
            'candidate_digest' => strtolower($digest), 'base_rpo_digest' => $ex->base_rpo_hash, 'batch_digest' => $ex->batch_digest,
            'provenance' => [ // proveniência CONGELADA (causal): execution + workspace + base + batch + itens
                'execution_id' => $ex->execution_id, 'workspace_unit_id' => $ex->workspace_unit_id, 'fence_token' => $ex->fence_token,
                'base_rpo_hash' => $ex->base_rpo_hash, 'batch_digest' => $ex->batch_digest,
                'item_digests' => PatchExecutionItem::where('patch_execution_id', $ex->id)->orderBy('batch_order')->pluck('item_digest')->all(),
                'execution_mode' => $ex->execution_mode, 'simulated' => true,
            ],
            'capability_adapter_version' => $ex->capability_adapter_version, 'classification' => $req->classification,
            'handoff_status' => PatchArtifactCandidate::HANDOFF_NONE, 'created_by' => $userId,
        ]);
    }
}
