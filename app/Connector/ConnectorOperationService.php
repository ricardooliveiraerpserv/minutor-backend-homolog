<?php

namespace App\Connector;

use App\Models\ConnectorAgent;
use App\Models\ConnectorEnvironmentState;
use App\Models\ConnectorEvent;
use App\Models\ConnectorOperation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connector-4.1 — orquestração de OPERAÇÕES (só 'start' nesta fase). Classe de segurança separada:
 *  - SEM retry destrutivo: nada de requeue/reclaim; 'expired' só p/ dispatchable NUNCA reivindicado.
 *  - a partir de 'claimed' → timeout/perda/dúvida (mesmo sem ACK de execution_committed) = 'indeterminate'.
 *  - execution_id imutável (nasce com a operação); autoridade final do desfecho = C-2 observado.
 * Toda transição alimenta connector_events (família operacoes da timeline C1). Sem secret/path/PID.
 */
class ConnectorOperationService
{
    private function cfg(string $k, $d = null)
    {
        return config("connector.operations.$k", $d);
    }

    private function emit(int $envId, ?string $ref, string $type, string $outcome, ?string $detail, array $meta): void
    {
        ConnectorEvent::create([
            'environment_id' => $envId, 'appserver_ref' => $ref, 'event_type' => $type,
            'outcome' => $outcome, 'detail' => $detail, 'meta' => $meta, 'occurred_at' => now(),
        ]);
    }

    /** Observado FRESCO do ambiente (estado corrente + frescor). null se não há inventário. */
    private function observedState(int $envId): ?array
    {
        $row = ConnectorEnvironmentState::where('environment_id', $envId)->first();
        if (! $row || $row->inventory_received_at === null) {
            return null;
        }
        $apps = collect($row->observed_json['appservers'] ?? []);
        $upApps = $apps->where('up', true);
        // Capability p/ OPERAR: existe AppServer up e TODO up reporta process_instance_id (C4.0).
        $capability = $upApps->isNotEmpty() && $upApps->every(fn ($a) => ! empty($a['process_instance_id']));

        return [
            'appservers'   => $apps->keyBy('ref')->all(),
            'received_at'  => $row->inventory_received_at,
            'stale_s'      => max(0, now()->getTimestamp() - $row->inventory_received_at->getTimestamp()),
            'capability'   => $capability,
        ];
    }

    /**
     * Cria a operação (start). Fail-closed: allowlist de tipo, escopo já validado no controller, capability
     * de piid, observado FRESCO, pré-condição (alvo down), concorrência (1 viva por appserver_ref E env).
     * Gera execution_id imutável e a pré-imagem. Maker-checker: nasce pending_approval (require_approval).
     * @return array{ok:bool, error?:string, op?:ConnectorOperation}
     */
    public function create(int $envId, ?int $customerId, string $ref, string $opType, int $requester, string $reason): array
    {
        if (! in_array($opType, (array) $this->cfg('types', []), true)) {
            return ['ok' => false, 'error' => 'op_type_not_allowed']; // stop/restart/etc → bloqueado na porta
        }
        $obs = $this->observedState($envId);
        if (! $obs) {
            return ['ok' => false, 'error' => 'no_fresh_observation'];
        }
        if ($obs['stale_s'] > (int) $this->cfg('observed_freshness', 120)) {
            return ['ok' => false, 'error' => 'observation_stale'];
        }
        if (! $obs['capability']) {
            return ['ok' => false, 'error' => 'process_instance_capability_required']; // sem sinal → bloqueia
        }
        $target = $obs['appservers'][$ref] ?? null;
        if (! $target) {
            return ['ok' => false, 'error' => 'appserver_not_observed'];
        }
        // Pré-condição do start: alvo DOWN. Se up → negar ANTES de criar/dispatch.
        if ($opType === 'start' && ($target['up'] ?? false) === true) {
            return ['ok' => false, 'error' => 'precondition_failed_appserver_up'];
        }

        $requireApproval = (bool) $this->cfg('require_approval', true);
        try {
            // Savepoint: a violação do índice único parcial (1 viva/appserver_ref E /env) reverte SÓ este
            // INSERT (não aborta a transação externa em teste) e é traduzida em 409 operation_in_flight.
            $op = DB::transaction(fn () => ConnectorOperation::create([
                'environment_id' => $envId, 'appserver_ref' => $ref, 'customer_id' => $customerId,
                'op_type' => $opType, 'status' => $requireApproval ? 'pending_approval' : 'dispatchable',
                'execution_id' => (string) Str::uuid(),                 // IMUTÁVEL, 1 por operação
                'requested_by' => $requester, 'reason' => mb_substr($reason, 0, 300),
                'approval_state' => $requireApproval ? 'pending' : 'not_required',
                'precondition_kind' => 'down',
                'precondition_snapshot' => ['up' => (bool) ($target['up'] ?? false), 'process_instance_id' => $target['process_instance_id'] ?? null, 'observed_at' => $obs['received_at']->toIso8601String()],
                'dispatchable_at' => $requireApproval ? null : now(),
                'transport_lease_expires_at' => $requireApproval ? null : now()->addSeconds((int) $this->cfg('transport_lease', 60)),
            ]));
        } catch (UniqueConstraintViolationException) {
            // 1 viva por appserver_ref E por environment_id.
            return ['ok' => false, 'error' => 'operation_in_flight'];
        }
        $this->emit($envId, $ref, 'operation_requested', 'info', "Operação {$opType} solicitada", ['op_type' => $opType, 'operation_id' => $op->id, 'execution_id' => substr($op->execution_id, 0, 8)]);
        if (! $requireApproval) {
            $this->emit($envId, $ref, 'operation_dispatched', 'info', 'Operação liberada p/ o agente', ['operation_id' => $op->id]);
        }

        return ['ok' => true, 'op' => $op];
    }

    /** Aprova (maker-checker: approver ≠ requester). pending_approval → dispatchable. */
    public function approve(ConnectorOperation $op, int $approver): array
    {
        return DB::transaction(function () use ($op, $approver) {
            $row = ConnectorOperation::whereKey($op->id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending_approval') {
                return ['ok' => false, 'error' => 'not_pending_approval'];
            }
            if ((int) $row->requested_by === $approver) {
                return ['ok' => false, 'error' => 'maker_cannot_approve']; // requested_by ≠ approved_by
            }
            $row->update([
                'status' => 'dispatchable', 'approval_state' => 'approved', 'approved_by' => $approver, 'approved_at' => now(),
                'dispatchable_at' => now(), 'transport_lease_expires_at' => now()->addSeconds((int) $this->cfg('transport_lease', 60)),
            ]);
            $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_approved', 'info', 'Operação aprovada', ['operation_id' => $row->id, 'approved_by' => $approver]);
            $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_dispatched', 'info', 'Operação liberada p/ o agente', ['operation_id' => $row->id]);

            return ['ok' => true, 'op' => $row->fresh()];
        });
    }

    /** Rejeita (checker nega a autorização): pending_approval → rejected (terminal, distinto de canceled). */
    public function reject(ConnectorOperation $op, int $approver): array
    {
        return DB::transaction(function () use ($op, $approver) {
            $row = ConnectorOperation::whereKey($op->id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending_approval') {
                return ['ok' => false, 'error' => 'not_pending_approval'];
            }
            if ((int) $row->requested_by === $approver) {
                return ['ok' => false, 'error' => 'maker_cannot_approve'];
            }
            $row->update(['status' => 'rejected', 'approval_state' => 'rejected', 'approved_by' => $approver, 'approved_at' => now()]);
            $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_rejected', 'info', 'Autorização negada pelo checker', ['operation_id' => $row->id]);

            return ['ok' => true, 'op' => $row->fresh()];
        });
    }

    /** Cancela ANTES do claim (requested/pending_approval/approved/dispatchable) → canceled (≠ rejected). */
    public function cancel(ConnectorOperation $op): array
    {
        return DB::transaction(function () use ($op) {
            $row = ConnectorOperation::whereKey($op->id)->lockForUpdate()->first();
            if (! $row) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            if (! in_array($row->status, ['requested', 'pending_approval', 'approved', 'dispatchable'], true)) {
                return ['ok' => false, 'error' => 'not_cancelable']; // depois do claim NÃO cancela
            }
            $row->update(['status' => 'canceled']);
            $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_canceled', 'info', 'Operação cancelada antes do claim', ['operation_id' => $row->id]);

            return ['ok' => true, 'op' => $row->fresh()];
        });
    }

    /** Claim ATÔMICO single-shot (SEM retry). dispatchable → claimed. Emite execution_id ao agente. */
    public function claimNext(ConnectorAgent $agent): ?ConnectorOperation
    {
        $envId = (int) $agent->environment_id;
        $deadline = (int) config('connector.operations.start.operational_deadline', 120);
        // Timestamps do relógio da APLICAÇÃO (não SQL now()): consistentes com received_at do inventário
        // e corretos sob transação (SQL now() congela no início da transação).
        $now = now(); $nowStr = $now->toDateTimeString(); $deadlineStr = $now->copy()->addSeconds($deadline)->toDateTimeString();
        $row = DB::selectOne(
            "UPDATE connector_operations SET
                status='claimed', claimed_by_agent_id=?, claimed_at=?, operational_deadline_at=?, updated_at=?
             WHERE id = (
                SELECT id FROM connector_operations
                 WHERE environment_id=? AND status='dispatchable'
                   AND (transport_lease_expires_at IS NULL OR transport_lease_expires_at > ?)
                 ORDER BY created_at FOR UPDATE SKIP LOCKED LIMIT 1)
             RETURNING id",
            [$agent->agent_id, $nowStr, $deadlineStr, $nowStr, $envId, $nowStr]
        );
        if (! $row) {
            return null;
        }
        $op = ConnectorOperation::find($row->id);
        $this->emit($envId, $op->appserver_ref, 'operation_claimed', 'info', 'Operação reivindicada pelo agente', ['operation_id' => $op->id, 'execution_id' => substr($op->execution_id, 0, 8)]);

        return $op;
    }

    /**
     * Operação em execução do AMBIENTE do agente (recupera resposta de claim perdida; idempotente).
     * Escopada por environment_id (não por agent_id): após restart do Conector o novo agente tem outra
     * identidade, mas é o mesmo conector do ambiente (1 agente ativo/ambiente) — recupera a mesma op,
     * mesmo execution_id. O binding do resultado é o execution_id (imutável), não o agent_id.
     */
    public function current(ConnectorAgent $agent): ?ConnectorOperation
    {
        return ConnectorOperation::where('environment_id', $agent->environment_id)
            ->whereIn('status', ['claimed', 'execution_committed', 'executing'])
            ->orderByDesc('id')->first();
    }

    /** Barreira: claimed → execution_committed (agente fez fsync ANTES de tocar no AppServer). */
    public function ack(ConnectorAgent $agent, ConnectorOperation $op, string $executionId): array
    {
        return DB::transaction(function () use ($agent, $op, $executionId) {
            $row = ConnectorOperation::whereKey($op->id)->lockForUpdate()->first();
            if (! $row || (int) $row->environment_id !== (int) $agent->environment_id || $row->execution_id !== $executionId) {
                return ['ok' => false, 'error' => 'invalid'];
            }
            if ($row->status !== 'claimed') {
                return ['ok' => false, 'error' => 'not_claimed'];
            }
            $row->update(['status' => 'execution_committed', 'execution_committed_at' => now()]);
            $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_execution_committed', 'info', 'Barreira de execução cruzada', ['operation_id' => $row->id]);

            return ['ok' => true, 'op' => $row->fresh()];
        });
    }

    /**
     * Resultado do agente. ok → 'verifying' (NUNCA sucesso direto); fail+pre_effect → 'failed';
     * fail+post_effect → 'indeterminate'. Aceito só com execution_id vigente + estado em execução.
     */
    public function result(ConnectorAgent $agent, ConnectorOperation $op, string $executionId, string $outcome, string $phase, ?string $detail): array
    {
        return DB::transaction(function () use ($agent, $op, $executionId, $outcome, $phase, $detail) {
            $row = ConnectorOperation::whereKey($op->id)->lockForUpdate()->first();
            if (! $row || (int) $row->environment_id !== (int) $agent->environment_id || $row->execution_id !== $executionId) {
                return ['ok' => false, 'error' => 'invalid'];
            }
            if (! in_array($row->status, ['claimed', 'execution_committed', 'executing'], true)) {
                return ['ok' => false, 'error' => 'not_in_execution'];
            }
            $base = ['agent_result' => $outcome, 'agent_result_at' => now(), 'agent_result_phase' => $phase, 'agent_result_detail' => $detail ? mb_substr($detail, 0, 200) : null, 'executing_at' => $row->executing_at ?? now()];
            if ($outcome === 'fail' && $phase === 'pre_effect') {
                $row->update($base + ['status' => 'failed']); // determinístico, sem efeito
                $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_failed', 'fail', 'Falhou antes do efeito', ['operation_id' => $row->id]);

                return ['ok' => true, 'status' => 'failed'];
            }
            if ($outcome === 'fail') { // fail pós-efeito → efeito incerto
                $row->update($base + ['status' => 'indeterminate']);
                $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_indeterminate', 'fail', 'Falha pós-barreira — efeito incerto', ['operation_id' => $row->id]);

                return ['ok' => true, 'status' => 'indeterminate'];
            }
            // ok → verifying (autoridade do desfecho é o C-2, não o agente).
            $row->update($base + ['status' => 'verifying']);
            $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_verifying', 'info', 'Execução local ok — verificando pelo C-2', ['operation_id' => $row->id]);

            return ['ok' => true, 'status' => 'verifying'];
        });
    }

    /**
     * Reaper LAZY (chamado em GET/reconcile). SEM requeue: dispatchable nunca reivindicado + transport lease
     * vencido → 'expired' (seguro). claimed/execution_committed/executing + operational deadline vencido →
     * 'indeterminate' (efeito potencialmente iniciado). NUNCA volta p/ dispatchable.
     */
    public function reap(ConnectorOperation $op): ConnectorOperation
    {
        $row = ConnectorOperation::whereKey($op->id)->lockForUpdate()->first();
        if (! $row) {
            return $op;
        }

        return DB::transaction(function () use ($row) {
            if ($row->status === 'dispatchable' && $row->transport_lease_expires_at && $row->transport_lease_expires_at->isPast()) {
                $row->update(['status' => 'expired']); // nunca reivindicado → nada aconteceu
                $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_expired', 'fail', 'Transport lease vencido sem claim (nada executou)', ['operation_id' => $row->id]);
            } elseif (in_array($row->status, ['claimed', 'execution_committed', 'executing'], true)
                && $row->operational_deadline_at && $row->operational_deadline_at->isPast()) {
                $row->update(['status' => 'indeterminate']); // a partir do claim: incerteza → indeterminate
                $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_indeterminate', 'fail', 'Operational deadline vencido — efeito incerto', ['operation_id' => $row->id]);
            }

            return $row->fresh();
        });
    }

    /**
     * Reconciliação (autoridade = C-2). Aplica a 'verifying' e 'indeterminate'. Pré-imagem down; exige
     * pós-observação CAUSAL (received_at > execution_committed_at ?? claimed_at). Start: pós up + piid →
     * reconciled_success; pós down → reconciled_noop (indeterminate) / contradicted (verifying diz ok);
     * pós up sem piid ou sem observação causal → unresolved. Nunca infere sucesso.
     */
    public function reconcile(ConnectorOperation $op): ConnectorOperation
    {
        $row = $this->reap($op); // primeiro materializa deadlines
        if (! in_array($row->status, ['verifying', 'indeterminate', 'reconciling'], true)) {
            return $row;
        }

        return DB::transaction(function () use ($row) {
            $r = ConnectorOperation::whereKey($row->id)->lockForUpdate()->first();
            if (! in_array($r->status, ['verifying', 'indeterminate', 'reconciling'], true)) {
                return $r->fresh();
            }
            $fromVerifying = $r->status === 'verifying';
            $r->update(['status' => 'reconciling', 'reconciliation_state' => 'pending']);

            $obs = $this->observedState((int) $r->environment_id);
            $floor = $r->execution_committed_at ?? $r->claimed_at;
            $post = $obs['appservers'][$r->appserver_ref] ?? null;
            // Causalidade: a pós-observação não pode ser ANTERIOR à operação. Timestamps têm precisão de
            // segundo → gte (mesmo segundo conta). Para start, a evidência forte do desfecho é a TRANSIÇÃO
            // de estado observada (pré-imagem down → pós up(B)); o timestamp só barra observação pré-operação.
            $causal = $obs && $floor && $obs['received_at']->gte($floor);

            $set = fn ($status, $recon, $detail, $auth = 'observed') => $r->update([
                'status' => $status, 'reconciliation_state' => $recon, 'reconciled_at' => now(),
                'outcome_authority' => $auth, 'postimage_snapshot' => $post,
            ]);

            if (! $causal || $post === null) {
                $set('unresolved', 'unresolved', 'sem observação causal conclusiva');
                $this->emit((int) $r->environment_id, $r->appserver_ref, 'operation_unresolved', 'fail', 'Sem observação C-2 conclusiva', ['operation_id' => $r->id]);

                return $r->fresh();
            }
            $up = (bool) ($post['up'] ?? false);
            $piid = $post['process_instance_id'] ?? null;
            if ($up && ! empty($piid)) {
                $set('reconciled_success', 'success', 'pós-imagem up com process_instance_id');
                $this->emit((int) $r->environment_id, $r->appserver_ref, 'operation_reconciled_success', 'ok', 'C-2 confirma up(B)', ['operation_id' => $r->id, 'process_instance_id' => substr((string) $piid, 0, 8)]);
            } elseif (! $up) {
                if ($fromVerifying) {
                    $set('contradicted', 'contradicted', 'agente reportou ok mas C-2 observa down');
                    $this->emit((int) $r->environment_id, $r->appserver_ref, 'operation_contradicted', 'fail', 'Agente ok × C-2 down', ['operation_id' => $r->id]);
                } else {
                    $set('reconciled_noop', 'noop', 'pós-imagem down — não executou');
                    $this->emit((int) $r->environment_id, $r->appserver_ref, 'operation_reconciled_noop', 'info', 'C-2 confirma que continuou down', ['operation_id' => $r->id]);
                }
            } else {
                // up sem process_instance_id → observabilidade insuficiente (não confirma incarnação).
                $set('unresolved', 'unresolved', 'up sem process_instance_id');
                $this->emit((int) $r->environment_id, $r->appserver_ref, 'operation_unresolved', 'fail', 'up sem process_instance_id', ['operation_id' => $r->id]);
            }

            return $r->fresh();
        });
    }
}
