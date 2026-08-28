<?php

namespace App\Connector;

use App\Models\ConnectorAgent;
use App\Models\ConnectorEnvironmentState;
use App\Models\ConnectorEvent;
use App\Models\ConnectorOperation;
use App\Models\EnvEnvironment;
use App\Models\RpoArtifact;
use App\Models\RpoTarget;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connector-4.x — orquestração de OPERAÇÕES destrutivas (start C4.1, stop C4.2). Classe separada:
 *  - SEM retry destrutivo: nada de requeue/reclaim; 'expired' só p/ dispatchable NUNCA reivindicado.
 *  - a partir de 'claimed' → timeout/perda/dúvida (mesmo sem ACK de execution_committed) = 'indeterminate'.
 *  - execution_id imutável (nasce com a operação); autoridade final do desfecho = C-2 observado.
 *  - stop: indisponibilidade DELIBERADA → gates próprios (janela obrigatória, presença ONLINE estrita,
 *    proteção do ÚLTIMO AppServer up) REVALIDADOS no dispatch; noop só após a janela de reconciliação.
 * Toda transição alimenta connector_events (família operacoes da timeline C1). Sem secret/path/PID.
 */
class ConnectorOperationService
{
    public function __construct(private PresenceDeriver $deriver, private RpoRegistryService $rpo)
    {
    }

    private function cfg(string $k, $d = null)
    {
        return config("connector.operations.$k", $d);
    }

    /** Status de presença do agente do ambiente (Conector-1). 'online' estrito é exigido p/ stop. */
    private function presenceStatus(int $envId): string
    {
        $hasAgent = ConnectorAgent::where('environment_id', $envId)->whereNull('revoked_at')->exists();
        $row = ConnectorEnvironmentState::where('environment_id', $envId)->first();
        if (! $hasAgent || ! $row) {
            return 'never_seen';
        }

        return $this->deriver->derive($row->last_seen_at, $row->agent_reported_status, $row->clock_offset_s, $row->last_error)['status'];
    }

    /** Decisão de janela de manutenção do tipo (política em CONFIG; server-side; snapshot gravado na op). */
    private function windowDecision(string $opType): array
    {
        $w = (array) $this->cfg("{$opType}.window", []);
        if (! (bool) ($w['enabled'] ?? false)) {
            return ['in_window' => true, 'policy' => ['enabled' => false]];
        }
        $tz = $w['timezone'] ?? 'UTC';
        $now = Carbon::now($tz);
        $inDay = in_array((int) $now->dayOfWeek, (array) ($w['days'] ?? []), true);
        $hm = $now->format('H:i');
        $inTime = ((string) ($w['start'] ?? '00:00')) <= $hm && $hm <= ((string) ($w['end'] ?? '23:59'));

        return ['in_window' => $inDay && $inTime, 'policy' => ['enabled' => true, 'timezone' => $tz, 'days' => (array) ($w['days'] ?? []), 'start' => $w['start'] ?? null, 'end' => $w['end'] ?? null]];
    }

    /**
     * Gates de segurança das operações DESTRUTIVAS que exigem alvo UP (stop e restart), contra uma
     * observação FRESCA (mesma foto): pré-condição up(A), capability (piid), presença ONLINE, último
     * AppServer, janela do tipo. Retorna erro DURO (bloqueio absoluto) e/ou violações liberáveis por override.
     */
    private function destructiveViolations(int $envId, array $obs, string $ref, string $opType): array
    {
        $target = $obs['appservers'][$ref] ?? null;
        if (! $target) {
            return ['blocked_error' => 'appserver_not_observed'];
        }
        if (($target['up'] ?? false) !== true) {
            return ['blocked_error' => 'precondition_failed_appserver_down']; // alvo precisa estar up(A)
        }
        $A = $target['process_instance_id'] ?? null;
        if (empty($A)) {
            return ['blocked_error' => 'process_instance_capability_required']; // up sem piid → não reconciliável
        }
        if ($this->presenceStatus($envId) !== 'online') {
            return ['blocked_error' => 'agent_not_online']; // precisa vivo p/ executar E reconciliar
        }
        // Último AppServer: conta OUTROS up na MESMA observação fresca (não mistura fotos).
        $otherUp = 0;
        foreach ($obs['appservers'] as $r => $a) {
            if ($r !== $ref && ($a['up'] ?? false) === true) { $otherUp++; }
        }
        $win = $this->windowDecision($opType);

        return [
            'blocked_error'   => null,
            'A'               => $A,
            'other_up'        => $otherUp,
            'violates_last'   => $otherUp < (int) $this->cfg("{$opType}.min_other_up", 1),
            'violates_window' => ! $win['in_window'],
            'window_policy'   => $win['policy'],
            'in_window'       => $win['in_window'],
        ];
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
    public function create(int $envId, ?int $customerId, string $ref, string $opType, int $requester, string $reason, bool $emergencyOverride = false, bool $hasOverridePerm = false): array
    {
        if (! in_array($opType, (array) $this->cfg('types', []), true)) {
            return ['ok' => false, 'error' => 'op_type_not_allowed']; // restart/compile/patch/etc → bloqueado na porta
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

        $precondKind = $opType === 'start' ? 'down' : 'up';
        $snapshot = ['observed_at' => $obs['received_at']->toIso8601String()];
        $overrideUsed = false;

        if ($opType === 'start') {
            // Pré-condição do start: alvo DOWN.
            if (($target['up'] ?? false) === true) {
                return ['ok' => false, 'error' => 'precondition_failed_appserver_up'];
            }
            $snapshot += ['up' => false, 'process_instance_id' => $target['process_instance_id'] ?? null];
        } else { // stop | restart — gates destrutivos (alvo up(A) + último AppServer + presença + janela)
            $v = $this->destructiveViolations($envId, $obs, $ref, $opType);
            if ($v['blocked_error'] !== null) {
                return ['ok' => false, 'error' => $v['blocked_error']];
            }
            $needsOverride = $v['violates_last'] || $v['violates_window'];
            if ($needsOverride) {
                if (! $emergencyOverride) {
                    return ['ok' => false, 'error' => $v['violates_last'] ? 'last_appserver_stop_blocked' : 'maintenance_window_closed'];
                }
                if (! $hasOverridePerm) {
                    return ['ok' => false, 'error' => 'override_permission_required']; // maker precisa de stop.override
                }
            }
            $overrideUsed = $needsOverride;
            $snapshot += [
                'up' => true, 'process_instance_id' => $v['A'], 'other_up_count' => $v['other_up'],
                'window' => $v['window_policy'], 'in_window' => $v['in_window'], 'emergency_override' => $overrideUsed,
                'override_reasons' => array_values(array_filter([$v['violates_last'] ? 'last_appserver' : null, $v['violates_window'] ? 'window' : null])),
            ];
        }

        $requireApproval = (bool) $this->cfg('require_approval', true);
        try {
            // Savepoint: a violação do índice único parcial (1 viva/appserver_ref E /env) reverte SÓ este INSERT.
            $op = DB::transaction(fn () => ConnectorOperation::create([
                'environment_id' => $envId, 'appserver_ref' => $ref, 'customer_id' => $customerId,
                'op_type' => $opType, 'status' => $requireApproval ? 'pending_approval' : 'dispatchable',
                'execution_id' => (string) Str::uuid(),                 // IMUTÁVEL, 1 por operação
                'requested_by' => $requester, 'reason' => mb_substr($reason, 0, 300),
                'approval_state' => $requireApproval ? 'pending' : 'not_required',
                'precondition_kind' => $precondKind,
                'precondition_snapshot' => $snapshot,
                'dispatchable_at' => $requireApproval ? null : now(),
                'transport_lease_expires_at' => $requireApproval ? null : now()->addSeconds((int) $this->cfg('transport_lease', 60)),
            ]));
        } catch (UniqueConstraintViolationException) {
            return ['ok' => false, 'error' => 'operation_in_flight']; // 1 viva por appserver_ref E por environment_id
        }
        $this->emit($envId, $ref, 'operation_requested', 'info', "Operação {$opType} solicitada", ['op_type' => $opType, 'operation_id' => $op->id, 'execution_id' => substr($op->execution_id, 0, 8)]);
        if ($overrideUsed) {
            // Override APARECE na timeline (nunca escondido só em reason).
            $this->emit($envId, $ref, 'operation_emergency_override', 'info', 'Emergency override de proteção', ['operation_id' => $op->id, 'reasons' => $snapshot['override_reasons']]);
        }
        if (! $requireApproval) {
            $this->emit($envId, $ref, 'operation_dispatched', 'info', 'Operação liberada p/ o agente', ['operation_id' => $op->id]);
        }

        return ['ok' => true, 'op' => $op];
    }

    /**
     * C5.2 — cria uma operação rpo_promote (SÓ activation_mode=hot). Baseada em TARGET (não appserver_ref
     * único): appserver_ref NULL, alvo em rpo_target_id + payload no precondition_snapshot. Gates fail-closed:
     *  - preview elegível (to registered, target confirmado+consistente, compat, capability disponível, from≠to);
     *  - activation_mode ∈ executable (hot) — SNAPSHOT congelado (revalidado no dispatch);
     *  - unidade física ÚNICA (publish_unit_id igual em todos os membros);
     *  - presença ONLINE (precisa vivo p/ executar E subir a coleta de reconciliação).
     * from_hash (central) + to_hash congelados. N-of-M por tipo de ambiente (prod=2). Nasce pending_approval.
     * hot NÃO tem last-AppServer/janela (sem outage deliberado). ZERO bytes/path.
     */
    public function createRpoPromote(RpoTarget $target, RpoArtifact $to, int $requester, string $reason): array
    {
        $envId = (int) $target->environment_id;
        $prev = $this->rpo->preview($target, $to, false);
        if (! ($prev['eligible'] ?? false)) {
            return ['ok' => false, 'error' => 'preview_ineligible', 'reasons' => $prev['reasons'] ?? []];
        }
        $mode = $prev['capability']['declared']['activation_mode'] ?? null;
        $execModes = (array) $this->cfg('rpo.executable_activation_modes', ['hot']);
        if (! in_array($mode, $execModes, true)) {
            return ['ok' => false, 'error' => 'activation_mode_not_executable', 'activation_mode' => $mode]; // v1 só hot
        }
        $pu = $this->rpo->publishUnitConsistency($target);
        if (! $pu['consistent']) {
            return ['ok' => false, 'error' => 'publish_unit_inconsistent', 'publish_unit' => $pu];
        }
        if ($this->presenceStatus($envId) !== 'online') {
            return ['ok' => false, 'error' => 'agent_not_online'];
        }
        $c = $prev['target_consistency'];
        $fromHash = $prev['from']['hash'] ?? null;
        $memberFrom = [];
        foreach (($c['per_appserver'] ?? []) as $m) { $memberFrom[$m['appserver_ref']] = $m['rpo_hash']; }
        $refs = array_keys($memberFrom);
        $env = EnvEnvironment::query()->whereKey($envId)->first(['type']);
        $policy = (array) $this->cfg('rpo.required_approvals', []);
        $required = (int) ($policy[$env->type ?? 'default'] ?? $policy['default'] ?? 1);

        $snapshot = [
            'kind' => 'rpo_promote',
            'observed_at' => now()->toIso8601String(),
            'from_hash' => $fromHash, 'to_artifact_id' => $to->id, 'to_hash' => $to->hash,
            'activation_mode' => $mode, 'publish_unit_id' => $pu['publish_unit_id'],
            'members' => $refs, 'member_from' => $memberFrom,
            'compatibility_snapshot' => $to->compatibility,
            'required_approvals' => $required, 'approvals' => [],
        ];
        try {
            $op = DB::transaction(fn () => ConnectorOperation::create([
                'environment_id' => $envId, 'appserver_ref' => null, 'customer_id' => $target->customer_id,
                'rpo_target_id' => $target->id, 'op_type' => 'rpo_promote', 'status' => 'pending_approval',
                'execution_id' => (string) Str::uuid(),
                'requested_by' => $requester, 'reason' => mb_substr($reason, 0, 300),
                'approval_state' => 'pending', 'precondition_kind' => 'rpo',
                'precondition_snapshot' => $snapshot,
            ]));
        } catch (UniqueConstraintViolationException) {
            return ['ok' => false, 'error' => 'operation_in_flight']; // 1 operação viva por ambiente
        }
        $this->emit($envId, null, 'operation_requested', 'info', 'Publicação de RPO (hot) solicitada', [
            'op_type' => 'rpo_promote', 'operation_id' => $op->id, 'execution_id' => substr($op->execution_id, 0, 8),
            'target_id' => $target->id, 'from' => substr((string) $fromHash, 0, 12), 'to' => substr($to->hash, 0, 12),
            'required_approvals' => $required,
        ]);

        return ['ok' => true, 'op' => $op];
    }

    /** Revalida os gates do rpo_promote no dispatch (topologia/capability podem ter mudado). Erro ou null. */
    private function revalidateRpoPromoteForDispatch(ConnectorOperation $op): ?string
    {
        $s = $op->precondition_snapshot ?? [];
        $target = RpoTarget::find($op->rpo_target_id);
        $to = RpoArtifact::find($s['to_artifact_id'] ?? 0);
        if (! $target || ! $to) {
            return 'target_or_artifact_missing';
        }
        if ($to->superseded_by_id !== null || $to->status !== 'registered') {
            return 'artifact_superseded'; // revisado/aposentado entre aprovação e dispatch
        }
        // capability disponível + activation_mode IGUAL ao snapshot (sem downgrade hot→requires_restart).
        $cap = $this->rpo->capability((int) $op->environment_id);
        if (! ($cap['available'] ?? false)) {
            return 'publish_capability_unavailable';
        }
        if (($cap['declared']['activation_mode'] ?? null) !== ($s['activation_mode'] ?? null)) {
            return 'activation_mode_changed'; // muda hot→requires_restart após aprovação → bloqueia
        }
        // target confirmado + consistente + from_hash observado == from_hash aprovado (divergência central).
        if ($target->status !== 'confirmed') {
            return 'target_not_confirmed';
        }
        $c = $this->rpo->targetConsistency($target);
        if (! ($c['consistent'] ?? false)) {
            return 'target_not_consistent';
        }
        if (($c['hash'] ?? null) !== ($s['from_hash'] ?? null)) {
            return 'from_hash_diverged'; // RPO ativo mudou desde a aprovação → publicação from→to inválida
        }
        // unidade física única mantida (mesmo publish_unit_id em todos os membros, == snapshot).
        $pu = $this->rpo->publishUnitConsistency($target);
        if (! ($pu['consistent'] ?? false) || ($pu['publish_unit_id'] ?? null) !== ($s['publish_unit_id'] ?? null)) {
            return 'publish_unit_changed';
        }
        if ($this->presenceStatus((int) $op->environment_id) !== 'online') {
            return 'agent_not_online';
        }
        if ($to->hash === ($c['hash'] ?? null)) {
            return 'from_equals_to'; // já publicado (backend não sabia) → reconcile nunca reaplica
        }

        return null;
    }

    /**
     * Aprova (maker-checker: approver ≠ requester). pending_approval → dispatchable. Se a operação foi
     * criada com emergency_override (viola último AppServer/janela), o CHECKER também precisa de
     * operations.stop.override — impede que um operador com override crie a exceção e a faça aprovar por
     * alguém sem autoridade equivalente. rpo_promote usa N-of-M (acumula aprovadores distintos ≠ requester).
     */
    public function approve(ConnectorOperation $op, int $approver, bool $hasOverridePerm = false): array
    {
        if ($op->op_type === 'rpo_promote') {
            return $this->approveNofM($op, $approver);
        }

        return DB::transaction(function () use ($op, $approver, $hasOverridePerm) {
            $row = ConnectorOperation::whereKey($op->id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending_approval') {
                return ['ok' => false, 'error' => 'not_pending_approval'];
            }
            if ((int) $row->requested_by === $approver) {
                return ['ok' => false, 'error' => 'maker_cannot_approve']; // requested_by ≠ approved_by
            }
            if (($row->precondition_snapshot['emergency_override'] ?? false) === true && ! $hasOverridePerm) {
                return ['ok' => false, 'error' => 'override_permission_required']; // checker também precisa do override
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

    /**
     * N-of-M (rpo_promote): acumula aprovadores DISTINTOS, todos ≠ requester. Só quando o nº de aprovações
     * atinge required (prod=2, homolog=1) a operação vira dispatchable. Cada aprovação parcial fica no
     * snapshot (approvals[]) e na timeline. approved_by = último aprovador (a distinção é garantida em código).
     */
    private function approveNofM(ConnectorOperation $op, int $approver): array
    {
        return DB::transaction(function () use ($op, $approver) {
            $row = ConnectorOperation::whereKey($op->id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending_approval') {
                return ['ok' => false, 'error' => 'not_pending_approval'];
            }
            if ((int) $row->requested_by === $approver) {
                return ['ok' => false, 'error' => 'maker_cannot_approve']; // maker nunca conta como checker
            }
            $snap = $row->precondition_snapshot ?? [];
            $approvals = (array) ($snap['approvals'] ?? []);
            foreach ($approvals as $a) {
                if ((int) ($a['by'] ?? 0) === $approver) {
                    return ['ok' => false, 'error' => 'already_approved']; // mesmo checker não conta 2×
                }
            }
            $approvals[] = ['by' => $approver, 'at' => now()->toIso8601String()];
            $required = (int) ($snap['required_approvals'] ?? 1);
            $snap['approvals'] = $approvals;
            $reached = count($approvals) >= $required;
            $row->precondition_snapshot = $snap;
            $row->approved_by = $approver;
            $row->approved_at = now();
            if ($reached) {
                $row->status = 'dispatchable';
                $row->approval_state = 'approved';
                $row->dispatchable_at = now();
                $row->transport_lease_expires_at = now()->addSeconds((int) $this->cfg('transport_lease', 60));
            }
            $row->save();
            $this->emit((int) $row->environment_id, null, 'operation_approved', 'info', "Aprovação {$approver} (" . count($approvals) . "/{$required})", ['operation_id' => $row->id, 'approved_by' => $approver, 'count' => count($approvals), 'required' => $required]);
            if ($reached) {
                $this->emit((int) $row->environment_id, null, 'operation_dispatched', 'info', 'Operação liberada p/ o agente (N-of-M satisfeito)', ['operation_id' => $row->id]);
            }

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

    /**
     * Resolução HUMANA de contradicted/unresolved (ciclo fecha em terminal; autoridade=human). O sistema
     * NUNCA infere sozinho o desfecho ambíguo — um humano decide success|noop|failed. Mapeia p/ os terminais
     * existentes (sem novo estado/índice). Congelamento do alvo só termina aqui.
     */
    public function resolve(ConnectorOperation $op, int $resolver, string $resolution): array
    {
        $map = ['success' => 'reconciled_success', 'noop' => 'reconciled_noop', 'failed' => 'failed'];
        if (! isset($map[$resolution])) {
            return ['ok' => false, 'error' => 'invalid_resolution'];
        }

        return DB::transaction(function () use ($op, $resolver, $resolution, $map) {
            $row = ConnectorOperation::whereKey($op->id)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['contradicted', 'unresolved'], true)) {
                return ['ok' => false, 'error' => 'not_resolvable']; // só resolve ambiguidade pendente de humano
            }
            $row->update([
                'status' => $map[$resolution], 'outcome_authority' => 'human', 'resolved_by' => $resolver, 'reconciled_at' => now(),
                'reconciliation_state' => $resolution === 'failed' ? 'contradicted' : $resolution,
            ]);
            $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_resolved', 'info', "Resolvido por humano: {$resolution}", ['operation_id' => $row->id, 'resolution' => $resolution]);

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

        return DB::transaction(function () use ($agent, $envId) {
            $now = now();
            // Trava a próxima op dispatchable do ambiente (lease de transporte ainda válido). lockForUpdate
            // serializa polls concorrentes; com 1 agente ativo/ambiente, o 2º vê status≠dispatchable.
            $row = ConnectorOperation::where('environment_id', $envId)->where('status', 'dispatchable')
                ->where(fn ($q) => $q->whereNull('transport_lease_expires_at')->orWhere('transport_lease_expires_at', '>', $now))
                ->orderBy('created_at')->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            // REVALIDAÇÃO no dispatch (topologia pode ter mudado desde a aprovação): stop/restart revalidam
            // pré-condição up(A), capability, presença ONLINE, último AppServer e janela contra a
            // observação FRESCA. Se violar (sem override autorizado da op) → NÃO entrega; bloqueia.
            $err = null;
            if (in_array($row->op_type, ['stop', 'restart'], true)) {
                $err = $this->revalidateDestructiveForDispatch($row);
            } elseif ($row->op_type === 'rpo_promote') {
                $err = $this->revalidateRpoPromoteForDispatch($row);
            }
            if ($err !== null) {
                $row->update(['status' => 'canceled', 'agent_result_detail' => $err]); // terminal seguro: nada executou
                $this->emit($envId, $row->appserver_ref, 'operation_dispatch_blocked', 'fail', 'Dispatch bloqueado na revalidação', ['operation_id' => $row->id, 'reason' => $err]);

                return null;
            }
            $deadline = (int) config("connector.operations.{$row->op_type}.operational_deadline", 120);
            $row->update([
                'status' => 'claimed', 'claimed_by_agent_id' => $agent->agent_id, 'claimed_at' => $now,
                'operational_deadline_at' => $now->copy()->addSeconds($deadline),
            ]);
            $this->emit($envId, $row->appserver_ref, 'operation_claimed', 'info', 'Operação reivindicada pelo agente', ['operation_id' => $row->id, 'execution_id' => substr($row->execution_id, 0, 8)]);

            return $row->fresh();
        });
    }

    /** Revalida os gates destrutivos (stop/restart) no dispatch. Retorna o erro de bloqueio ou null. */
    private function revalidateDestructiveForDispatch(ConnectorOperation $op): ?string
    {
        $obs = $this->observedState((int) $op->environment_id);
        if (! $obs || $obs['stale_s'] > (int) $this->cfg('observed_freshness', 120)) {
            return 'observation_stale'; // fotografia incompleta/velha → fail-closed
        }
        $v = $this->destructiveViolations((int) $op->environment_id, $obs, $op->appserver_ref, $op->op_type);
        if ($v['blocked_error'] !== null) {
            return $v['blocked_error']; // ex.: agent_not_online, precondition_failed_appserver_down
        }
        $overrideAuthorized = ($op->precondition_snapshot['emergency_override'] ?? false) === true;
        if ($v['violates_last'] && ! $overrideAuthorized) {
            return 'last_appserver_stop_blocked'; // ex.: outro AppServer caiu entre aprovação e dispatch
        }
        if ($v['violates_window'] && ! $overrideAuthorized) {
            return 'maintenance_window_closed';
        }

        return null;
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

    /**
     * CAUSALIDADE FORTE (C4.3): registra a observação do alvo vinda de uma coleta CORRELACIONADA à operação
     * (inventário com trigger.operation_id). Chamado pelo ConnectorInventoryProcessor (pipeline C-2 único).
     * Só vincula se a op é do MESMO ambiente/agente e está em voo. Guarda {up, piid, received_at, correlated}
     * em postimage_snapshot — a AUTORIDADE do desfecho do restart (não o observed_json periódico).
     */
    public function recordReconcileObservation(ConnectorAgent $agent, int $opId, array $appserversByRef, array $rpoByRef, \Illuminate\Support\Carbon $receivedAt): bool
    {
        $row = ConnectorOperation::whereKey($opId)->lockForUpdate()->first();
        // Escopo por AMBIENTE (não por agent_id): após restart do Conector o novo agente tem outra
        // identidade mas é o mesmo conector do ambiente (1 ativo/ambiente) — a coleta de reconciliação
        // que ele sobe após recuperar por /current deve valer. O binding é operation_id + ambiente.
        if (! $row || (int) $row->environment_id !== (int) $agent->environment_id) {
            return false;
        }
        if (! in_array($row->status, ['claimed', 'execution_committed', 'executing', 'verifying', 'indeterminate', 'reconciling'], true)) {
            return false;
        }
        if ($row->op_type === 'rpo_promote') {
            // C5.2 — o TARGET INTEIRO é a autoridade: hash/up/publish_unit de TODOS os membros esperados.
            $members = (array) ($row->precondition_snapshot['members'] ?? []);
            $obs = [];
            foreach ($members as $ref) {
                $a = $appserversByRef[$ref] ?? null;
                $rp = $rpoByRef[$ref] ?? null;
                $obs[$ref] = [
                    'rpo_hash' => $rp['hash'] ?? null,
                    'up' => (bool) ($a['up'] ?? false),
                    'publish_unit_id' => $rp['publish_unit_id'] ?? null,
                ];
            }
            $row->update(['postimage_snapshot' => ['kind' => 'rpo_promote', 'members' => $obs, 'received_at' => $receivedAt->toIso8601String(), 'correlated' => true]]);

            return true;
        }
        $t = $appserversByRef[$row->appserver_ref] ?? null;
        $row->update(['postimage_snapshot' => [
            'up' => (bool) ($t['up'] ?? false),
            'process_instance_id' => $t['process_instance_id'] ?? null,
            'received_at' => $receivedAt->toIso8601String(),
            'correlated' => true,
        ]]);

        return true;
    }

    /**
     * DOIS marcadores DISTINTOS de journal (ambos fsync no agente antes do POST):
     *  - phase=execution_committed: barreira claimed → execution_committed. "esta execução adquiriu
     *    IRREVOGAVELMENTE o direito de tentar o efeito; nunca outra com este execution_id." (at-most-once)
     *  - phase=effect_started: execution_committed → executing. "o agente entrou na região onde o RPO PODE
     *    ter sido alterado." Só EVIDÊNCIA/diagnóstico — NUNCA base de retry. committed sem effect_started
     *    continua sem retry. Distingue committed=true/effect_started=false de =true num incidente.
     */
    public function ack(ConnectorAgent $agent, ConnectorOperation $op, string $executionId, string $phase = 'execution_committed'): array
    {
        return DB::transaction(function () use ($agent, $op, $executionId, $phase) {
            $row = ConnectorOperation::whereKey($op->id)->lockForUpdate()->first();
            if (! $row || (int) $row->environment_id !== (int) $agent->environment_id || $row->execution_id !== $executionId) {
                return ['ok' => false, 'error' => 'invalid'];
            }
            if ($phase === 'effect_started') {
                if ($row->status !== 'execution_committed') {
                    return ['ok' => false, 'error' => 'not_committed']; // effect_started exige a barreira antes
                }
                $row->update(['status' => 'executing', 'executing_at' => $row->executing_at ?? now(), 'effect_started_at' => now()]);
                $this->emit((int) $row->environment_id, $row->appserver_ref, 'operation_effect_started', 'info', 'Efeito potencialmente iniciado (região de mutação do RPO)', ['operation_id' => $row->id]);

                return ['ok' => true, 'op' => $row->fresh()];
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
            // Causalidade: a pós-observação não pode ser ANTERIOR à operação (timestamps precisão de segundo
            // → gte). A evidência FORTE do desfecho é a TRANSIÇÃO de estado observada.
            $causal = (bool) ($obs && $floor && $obs['received_at']->gte($floor));
            $rw = (int) $this->cfg("{$r->op_type}.reconcile_window", 180);
            $windowElapsed = $floor && now()->getTimestamp() >= ($floor->getTimestamp() + $rw);
            $up = $post !== null ? (bool) ($post['up'] ?? false) : null;
            $piid = $post['process_instance_id'] ?? null;

            $set = fn ($status, $recon, $detail, $auth = 'observed') => $r->update([
                'status' => $status, 'reconciliation_state' => $recon, 'reconciled_at' => now(),
                'outcome_authority' => $auth, 'postimage_snapshot' => $post,
            ]);
            $out = fn ($status, $recon, $type, $outcome, $detail, $meta = []) => [$set($status, $recon, $detail), $this->emit((int) $r->environment_id, $r->appserver_ref, $type, $outcome, $detail, ['operation_id' => $r->id] + $meta)];

            if ($r->op_type === 'start') {
                // C4.1 CONGELADO (imediato): up+piid→success; down→noop|contradicted; senão unresolved.
                if (! $causal || $post === null) {
                    $out('unresolved', 'unresolved', 'operation_unresolved', 'fail', 'Sem observação C-2 conclusiva');
                } elseif ($up && ! empty($piid)) {
                    $out('reconciled_success', 'success', 'operation_reconciled_success', 'ok', 'C-2 confirma up(B)', ['process_instance_id' => substr((string) $piid, 0, 8)]);
                } elseif ($up === false) {
                    $fromVerifying
                        ? $out('contradicted', 'contradicted', 'operation_contradicted', 'fail', 'Agente ok × C-2 down')
                        : $out('reconciled_noop', 'noop', 'operation_reconciled_noop', 'info', 'C-2 confirma que continuou down');
                } else {
                    $out('unresolved', 'unresolved', 'operation_unresolved', 'fail', 'up sem process_instance_id');
                }

                return $r->fresh();
            }

            if ($r->op_type === 'restart') {
                // ── restart ──: down transiente → up(B). Autoridade FORTE = coleta de reconciliação
                // CORRELACIONADA (trigger.operation_id) gravada em postimage_snapshot; NÃO usa o observed_json
                // periódico. Sucesso EXCLUSIVO: up(B), B≠A, com received_at ≥ execution_committed_at.
                $A = $r->precondition_snapshot['process_instance_id'] ?? null;
                $co = $r->postimage_snapshot;
                $coCausal = is_array($co) && ! empty($co['correlated']) && ! empty($co['received_at'])
                    && $floor && Carbon::parse($co['received_at'])->gte($floor);
                // NÃO sobrescreve postimage_snapshot (é a evidência correlacionada).
                $setR = fn ($status, $recon) => $r->update(['status' => $status, 'reconciliation_state' => $recon, 'reconciled_at' => now(), 'outcome_authority' => 'observed']);
                $outR = fn ($status, $recon, $type, $outcome, $detail, $meta = []) => [$setR($status, $recon), $this->emit((int) $r->environment_id, $r->appserver_ref, $type, $outcome, $detail, ['operation_id' => $r->id] + $meta)];

                if (! $coCausal) {
                    if (! $windowElapsed) { return $r->fresh(); } // aguarda a coleta de reconciliação da operação
                    $outR('unresolved', 'unresolved', 'operation_unresolved', 'fail', 'Sem coleta de reconciliação correlacionada/causal na janela');

                    return $r->fresh();
                }
                $cUp = (bool) ($co['up'] ?? false); $cPiid = $co['process_instance_id'] ?? null;
                if ($cUp && ! empty($cPiid) && $cPiid !== $A) {
                    $outR('reconciled_success', 'success', 'operation_reconciled_success', 'ok', 'C-2 confirma up(B), B≠A', ['from' => substr((string) $A, 0, 8), 'to' => substr((string) $cPiid, 0, 8)]);
                } elseif ($cUp && $cPiid === $A) { // incarnação inalterada → não reiniciou
                    if ($fromVerifying) {
                        $outR('contradicted', 'contradicted', 'operation_contradicted', 'fail', 'Agente ok × up(A) — não reiniciou (incarnação inalterada)');
                    } elseif ($windowElapsed) {
                        $outR('reconciled_noop', 'noop', 'operation_reconciled_noop', 'info', 'up(A) durante toda a janela — não reiniciou');
                    } else {
                        return $r->fresh(); // up(A) cedo → aguarda
                    }
                } elseif (! $cUp) { // down: transiente esperado; se PERSISTIR toda a janela → falha de recuperação
                    if (! $windowElapsed) { return $r->fresh(); }
                    $outR('contradicted', 'recovery_failed', 'operation_recovery_failed', 'fail', 'Falha de recuperação — AppServer não retornou após restart');
                } else { // up sem process_instance_id
                    if (! $windowElapsed) { return $r->fresh(); }
                    $outR('unresolved', 'unresolved', 'operation_unresolved', 'fail', 'up sem process_instance_id na janela');
                }

                return $r->fresh();
            }

            if ($r->op_type === 'rpo_promote') {
                // ── rpo_promote (hot) ──: autoridade = coleta CORRELACIONADA (trigger.operation_id) do TARGET
                // INTEIRO gravada em postimage_snapshot (NÃO o observed_json periódico). Sucesso EXCLUSIVO:
                // TODOS os membros em to_hash + disponíveis (hot espera up), received_at ≥ execution_committed_at.
                $fromHash = $r->precondition_snapshot['from_hash'] ?? null;
                $toHash = $r->precondition_snapshot['to_hash'] ?? null;
                $members = (array) ($r->precondition_snapshot['members'] ?? []);
                $co = $r->postimage_snapshot;
                $coCausal = is_array($co) && ($co['kind'] ?? null) === 'rpo_promote' && ! empty($co['correlated'])
                    && ! empty($co['received_at']) && $floor && Carbon::parse($co['received_at'])->gte($floor);
                $setR = fn ($status, $recon) => $r->update(['status' => $status, 'reconciliation_state' => $recon, 'reconciled_at' => now(), 'outcome_authority' => 'observed']);
                $outR = fn ($status, $recon, $type, $outcome, $detail, $meta = []) => [$setR($status, $recon), $this->emit((int) $r->environment_id, null, $type, $outcome, $detail, ['operation_id' => $r->id] + $meta)];

                if (! $coCausal) {
                    // Snapshot periódico (sem correlação) NÃO conclui. Aguarda a coleta correlacionada da operação.
                    if (! $windowElapsed) { return $r->fresh(); }
                    $outR('unresolved', 'unresolved', 'operation_unresolved', 'fail', 'Sem coleta de reconciliação correlacionada/causal do target na janela');

                    return $r->fresh();
                }
                $obsM = (array) ($co['members'] ?? []);
                $allObserved = ! empty($members); $anyDown = false; $toCount = 0; $fromCount = 0; $otherCount = 0;
                foreach ($members as $ref) {
                    $h = $obsM[$ref]['rpo_hash'] ?? null;
                    if ($h === null) { $allObserved = false; continue; }
                    if (($obsM[$ref]['up'] ?? false) !== true) { $anyDown = true; }
                    if ($h === $toHash) { $toCount++; } elseif ($h === $fromHash) { $fromCount++; } else { $otherCount++; }
                }
                if (! $allObserved) { // membro esperado ausente/sem hash → não conclui até a janela
                    if (! $windowElapsed) { return $r->fresh(); }
                    $outR('unresolved', 'unresolved', 'operation_unresolved', 'fail', 'Observação do target incompleta na janela');

                    return $r->fresh();
                }
                $n = count($members);
                if ($otherCount > 0) { // hash inesperado (≠from,≠to) em algum membro → contradição
                    $outR('contradicted', 'contradicted', 'operation_contradicted', 'fail', 'C-2: hash inesperado no target (≠from,≠to)');
                } elseif ($toCount === $n) { // todos aplicaram to_hash
                    if (! $anyDown) { // sucesso técnico → last_successfully_published (NÃO known_good)
                        $setR('reconciled_success', 'success');
                        RpoTarget::whereKey($r->rpo_target_id)->update(['last_successfully_published' => ['artifact_id' => $r->precondition_snapshot['to_artifact_id'] ?? null, 'hash' => $toHash, 'at' => now()->toIso8601String()]]);
                        $this->emit((int) $r->environment_id, null, 'operation_reconciled_success', 'ok', 'C-2 confirma to_hash em todo o target (hot)', ['operation_id' => $r->id, 'to' => substr((string) $toHash, 0, 12)]);
                    } else { // RPO=to aplicado mas membro não disponível → recovery_failed (SEM auto-rollback)
                        if (! $windowElapsed) { return $r->fresh(); } // indisponibilidade transiente antes da janela
                        $outR('contradicted', 'recovery_failed', 'operation_recovery_failed', 'fail', 'RPO=to aplicado, mas AppServer do target não retornou disponível');
                    }
                } elseif ($fromCount === $n) { // troca não ocorreu (todos em from)
                    if ($fromVerifying) {
                        $outR('contradicted', 'contradicted', 'operation_contradicted', 'fail', 'Agente ok × RPO inalterado (from) em todo o target');
                    } elseif ($windowElapsed) {
                        $outR('reconciled_noop', 'noop', 'operation_reconciled_noop', 'info', 'C-2: target permaneceu em from na janela — troca não ocorreu');
                    } else {
                        return $r->fresh();
                    }
                } else { // mistura from/to → aplicação PARCIAL (freeze; humano). Aguarda janela p/ evitar flapping.
                    if (! $windowElapsed) { return $r->fresh(); }
                    $outR('contradicted', 'partial_apply', 'operation_partial_apply', 'fail', 'Aplicação parcial: membros do target divergem (from/to)');
                }

                return $r->fresh();
            }

            // ── stop ──: sucesso = down; up(A)=noop SÓ após a janela (stop pode estar em andamento) ou
            // contradicted (verifying); up(B)=contradicted (nova incarnação ≠ parada); up-sem-piid/sem-obs
            // = unresolved SÓ após a janela. Antes da janela, casos inconclusivos PERMANECEM reconciling.
            $A = $r->precondition_snapshot['process_instance_id'] ?? null;
            if (! $causal || $post === null) {
                if (! $windowElapsed) { return $r->fresh(); } // aguarda observação dentro da janela
                $out('unresolved', 'unresolved', 'operation_unresolved', 'fail', 'Sem observação C-2 conclusiva na janela');
            } elseif ($up === false) {
                $out('reconciled_success', 'success', 'operation_reconciled_success', 'ok', 'C-2 confirma up(A)→down');
            } elseif (! empty($piid) && $piid !== $A) {
                // Voltou como NOVA incarnação → houve restart/crash-restart, não parada. Ambíguo → humano.
                $out('contradicted', 'contradicted', 'operation_contradicted', 'fail', 'C-2 up(B≠A): mudança de instância, não parada', ['from' => substr((string) $A, 0, 8), 'to' => substr((string) $piid, 0, 8)]);
            } elseif (! empty($piid) && $piid === $A) {
                if ($fromVerifying) {
                    $out('contradicted', 'contradicted', 'operation_contradicted', 'fail', 'Agente ok × C-2 ainda up(A)');
                } elseif ($windowElapsed) {
                    $out('reconciled_noop', 'noop', 'operation_reconciled_noop', 'info', 'C-2 up(A) durante toda a janela — não parou');
                } else {
                    return $r->fresh(); // up(A) cedo: stop pode estar em andamento → permanece reconciling
                }
            } else { // up sem piid
                if (! $windowElapsed) { return $r->fresh(); }
                $out('unresolved', 'unresolved', 'operation_unresolved', 'fail', 'up sem process_instance_id na janela');
            }

            return $r->fresh();
        });
    }
}
