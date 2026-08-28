<?php

namespace App\Http\Controllers;

use App\Connector\ConnectorOperationService;
use App\Models\ConnectorAgent;
use App\Models\ConnectorOperation;
use App\Models\EnvEnvironment;
use App\Models\RpoArtifact;
use App\Models\RpoQualification;
use App\Models\RpoTarget;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connector-4.1 — OPERAÇÕES (só 'start'). AGENTE (assinado, outbound-only): next/current/ack/result.
 * ADMIN (sessão + escopo): criar (perm start), aprovar/rejeitar (perm approve), cancelar, ver, reconciliar.
 * Escopo do agente vem SEMPRE do registro (server-side). Nenhum path/PID/segredo cruza a fronteira.
 */
class ConnectorOperationController extends Controller
{
    public function __construct(
        private ConnectorOperationService $ops,
        private SourceDocCustomerScope $scope,
    ) {
    }

    // ── AGENTE (outbound-only) ────────────────────────────────────────────────

    /** GET /connector/operations/next — claim single-shot (SEM retry). Retorna execution_id. */
    public function next(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('connector_agent');
        $op = $this->ops->claimNext($agent);

        return $op ? response()->json(['data' => $this->agentView($op)]) : response()->json(null, 204);
    }

    /** GET /connector/operations/current — operação atualmente reivindicada (recupera claim perdido). */
    public function current(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('connector_agent');
        $op = $this->ops->current($agent);

        return $op ? response()->json(['data' => $this->agentView($op)]) : response()->json(null, 204);
    }

    /**
     * POST /connector/operations/{id}/ack {execution_id, phase?} — DOIS marcadores de journal:
     * phase=execution_committed (barreira, default) e phase=effect_started (efeito potencialmente iniciado;
     * só evidência/diagnóstico — nunca base de retry).
     */
    public function ack(Request $request, int $id): JsonResponse
    {
        $agent = $request->attributes->get('connector_agent');
        $data = $request->validate([
            'execution_id' => 'required|uuid',
            // C5.2b — publish_effect_started / restart_effect_started especializados (requires_restart).
            'phase'        => 'nullable|in:execution_committed,effect_started,publish_effect_started,restart_effect_started',
        ]);
        $op = $this->agentOperation($agent, $id);
        if (! $op) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }
        $r = $this->ops->ack($agent, $op, $data['execution_id'], $data['phase'] ?? 'execution_committed');

        return response()->json(['ok' => $r['ok']], $r['ok'] ? 200 : 409);
    }

    /** POST /connector/operations/{id}/result {execution_id, outcome, phase, error?}. ok→verifying. */
    public function result(Request $request, int $id): JsonResponse
    {
        $agent = $request->attributes->get('connector_agent');
        $data = $request->validate([
            'execution_id' => 'required|uuid',
            'outcome'      => 'required|in:ok,fail',
            'phase'        => 'required|in:pre_effect,post_effect',
            'error'        => 'nullable|string|max:200',
        ]);
        $op = $this->agentOperation($agent, $id);
        if (! $op) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }
        $detail = isset($data['error']) ? $this->sanitize((string) $data['error']) : null;
        $r = $this->ops->result($agent, $op, $data['execution_id'], $data['outcome'], $data['phase'], $detail);

        return response()->json(['ok' => $r['ok'], 'status' => $r['status'] ?? null], $r['ok'] ? 200 : 409);
    }

    // ── ADMIN (sessão + escopo) ───────────────────────────────────────────────

    /**
     * POST /prosight/environments/{environmentId}/operations {op_type, appserver_ref, reason, emergency_override?}
     * Permissão POR TIPO (operations.start | operations.stop), enforce no controller. emergency_override
     * (janela fechada / último AppServer) exige operations.stop.override. Anti-IDOR 404.
     */
    public function create(Request $request, int $environmentId): JsonResponse
    {
        $env = EnvEnvironment::query()->whereKey($environmentId)->first(['id', 'customer_id']);
        if (! $env || ! $this->scope->canAccessCustomerId($request->user(), (int) $env->customer_id)) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }
        $data = $request->validate([
            'op_type'            => 'required|string|max:12',
            'appserver_ref'      => 'required|uuid',
            'reason'             => 'required|string|max:300',
            'emergency_override' => 'nullable|boolean',
        ]);
        $permByType = ['start' => 'prosight.operations.start', 'stop' => 'prosight.operations.stop', 'restart' => 'prosight.operations.restart'];
        $need = $permByType[$data['op_type']] ?? null;
        if ($need === null) {
            return response()->json(['error' => 'op_type_not_allowed'], 422);
        }
        if (! $this->hasPerm($request->user(), $need)) {
            return response()->json(['error' => 'forbidden'], 403); // perm granular por tipo (não herda execute)
        }
        $overridePerm = ['stop' => 'prosight.operations.stop.override', 'restart' => 'prosight.operations.restart.override'][$data['op_type']] ?? null;
        $hasOverride = $overridePerm !== null && $this->hasPerm($request->user(), $overridePerm);
        $r = $this->ops->create((int) $env->id, (int) $env->customer_id, $data['appserver_ref'], $data['op_type'], $request->user()->id, $data['reason'], (bool) ($data['emergency_override'] ?? false), $hasOverride);
        if (! $r['ok']) {
            $code = match ($r['error']) {
                'operation_in_flight' => 409,
                'override_permission_required' => 403,
                default => 422,
            };

            return response()->json(['error' => $r['error']], $code);
        }

        return response()->json(['data' => $this->adminView($r['op'])], 201);
    }

    /**
     * POST /prosight/rpo/targets/{id}/promote {to_artifact_id, reason} — C5.2: cria operação rpo_promote
     * (SÓ hot). Perm prosight.operations.rpo.promote. Escopo por customer_id (anti-IDOR 404). ZERO bytes/path.
     */
    public function promote(Request $request, int $id): JsonResponse
    {
        $target = RpoTarget::find($id);
        if (! $target || ! $this->scope->canAccessCustomerId($request->user(), (int) $target->customer_id)) {
            return response()->json(['message' => 'Target não encontrado.'], 404);
        }
        if (! $this->hasPerm($request->user(), 'prosight.operations.rpo.promote')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $data = $request->validate(['to_artifact_id' => 'required|integer', 'reason' => 'required|string|max:300', 'emergency_override' => 'nullable|boolean']);
        $to = RpoArtifact::find((int) $data['to_artifact_id']);
        if (! $to || ! $this->scope->canAccessCustomerId($request->user(), (int) $to->customer_id)) {
            return response()->json(['message' => 'Artefato não encontrado.'], 404);
        }
        // C5.2b — override do last-AppServer (requires_restart single-member) exige rpo.override no MAKER.
        $hasOverride = $this->hasPerm($request->user(), 'prosight.operations.rpo.override');
        $r = $this->ops->createRpoPromote($target, $to, $request->user()->id, $data['reason'], (bool) ($data['emergency_override'] ?? false), $hasOverride);
        if (! $r['ok']) {
            $code = match ($r['error']) {
                'operation_in_flight' => 409,
                'override_permission_required' => 403,
                default => 422,
            };

            return response()->json(['error' => $r['error'], 'reasons' => $r['reasons'] ?? null] + array_filter(['activation_mode' => $r['activation_mode'] ?? null, 'restart_strategy' => $r['restart_strategy'] ?? null]), $code);
        }

        return response()->json(['data' => $this->adminView($r['op'])], 201);
    }

    /**
     * POST /prosight/rpo/targets/{id}/rollback {qualification_id, reason} — C5.3: rollback GOVERNADO (SÓ hot)
     * para uma qualificação known_good CONTEXTUAL válida (autoridade NOMEADA por qualification_id — o backend
     * resolve qualification_id → artifact_id → hash e valida contexto). Perm PRÓPRIA rpo.rollback (NÃO herda
     * de promote). Escopo por customer_id (anti-IDOR 404). ZERO bytes/path.
     */
    public function rollback(Request $request, int $id): JsonResponse
    {
        $target = RpoTarget::find($id);
        if (! $target || ! $this->scope->canAccessCustomerId($request->user(), (int) $target->customer_id)) {
            return response()->json(['message' => 'Target não encontrado.'], 404);
        }
        if (! $this->hasPerm($request->user(), 'prosight.operations.rpo.rollback')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $data = $request->validate(['qualification_id' => 'required|integer', 'reason' => 'required|string|max:300']);
        // Autoridade NOMINAL: a qualificação precisa ser DESTE target (contexto validado no service também).
        $q = RpoQualification::find((int) $data['qualification_id']);
        if (! $q || (int) $q->rpo_target_id !== (int) $target->id) {
            return response()->json(['message' => 'Qualificação não encontrada.'], 404);
        }
        $r = $this->ops->createRpoRollback($target, $q, $request->user()->id, $data['reason']);
        if (! $r['ok']) {
            $code = $r['error'] === 'operation_in_flight' ? 409 : 422;

            return response()->json(['error' => $r['error'], 'reasons' => $r['reasons'] ?? null], $code);
        }

        return response()->json(['data' => $this->adminView($r['op'])], 201);
    }

    /** POST /prosight/operations/{id}/approve — perm por tipo, requester ≠ approver. */
    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, 'approve');
    }

    /** POST /prosight/operations/{id}/reject — perm por tipo. */
    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, 'reject');
    }

    /** Perm de aprovação POR TIPO: rpo_promote/rpo_rollback → operations.rpo.approve; lifecycle → operations.approve. */
    private function approvePermFor(ConnectorOperation $op): string
    {
        return in_array($op->op_type, ['rpo_promote', 'rpo_rollback'], true) ? 'prosight.operations.rpo.approve' : 'prosight.operations.approve';
    }

    private function decide(Request $request, int $id, string $action): JsonResponse
    {
        $op = $this->scopedOperation($request, $id);
        if (! $op) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }
        if (! $this->hasPerm($request->user(), $this->approvePermFor($op))) {
            return response()->json(['error' => 'forbidden'], 403); // maker-checker: capability de aprovação por tipo
        }
        // C5.2b — se a operação usou emergency_override (last-AppServer requires_restart), o CHECKER também
        // precisa de rpo.override (maker E checker) — impede aprovar exceção por quem não tem autoridade.
        if ($action === 'approve' && $op->op_type === 'rpo_promote' && ($op->precondition_snapshot['emergency_override'] ?? false) === true
            && ! $this->hasPerm($request->user(), 'prosight.operations.rpo.override')) {
            return response()->json(['error' => 'override_permission_required'], 403);
        }
        $overridePerm = ['stop' => 'prosight.operations.stop.override', 'restart' => 'prosight.operations.restart.override'][$op->op_type] ?? null;
        $hasOverride = $overridePerm !== null && $this->hasPerm($request->user(), $overridePerm);
        $r = $action === 'approve'
            ? $this->ops->approve($op, $request->user()->id, $hasOverride)
            : $this->ops->reject($op, $request->user()->id);
        if (! $r['ok']) {
            $code = match ($r['error']) {
                'maker_cannot_approve' => 422,
                'override_permission_required' => 403,
                default => 409,
            };

            return response()->json(['error' => $r['error']], $code);
        }

        return response()->json(['data' => $this->adminView($r['op'])]);
    }

    /** POST /prosight/operations/{id}/cancel — perm POR TIPO (start|stop); maker cancela antes do claim. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $op = $this->scopedOperation($request, $id);
        if (! $op) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }
        $need = ['start' => 'prosight.operations.start', 'stop' => 'prosight.operations.stop', 'restart' => 'prosight.operations.restart'][$op->op_type] ?? null;
        if ($need && ! $this->hasPerm($request->user(), $need)) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $r = $this->ops->cancel($op);
        if (! $r['ok']) {
            return response()->json(['error' => $r['error']], $r['error'] === 'not_found' ? 404 : 409);
        }

        return response()->json(['data' => $this->adminView($r['op'])]);
    }

    /** GET /prosight/operations/{id} — perm view. Reaper LAZY (materializa expired/indeterminate). */
    public function show(Request $request, int $id): JsonResponse
    {
        $op = $this->scopedOperation($request, $id);
        if (! $op) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }
        $op = $this->ops->reap($op); // deadlines → expired/indeterminate (nunca requeue)

        return response()->json(['data' => $this->adminView($op)]);
    }

    /** GET /prosight/environments/{environmentId}/operations — perm view. */
    public function index(Request $request, int $environmentId): JsonResponse
    {
        $env = EnvEnvironment::query()->whereKey($environmentId)->first(['id', 'customer_id']);
        if (! $env || ! $this->scope->canAccessCustomerId($request->user(), (int) $env->customer_id)) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }
        $rows = ConnectorOperation::where('environment_id', $env->id)->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => ['environment_id' => (int) $env->id, 'operations' => $rows->map(fn ($o) => $this->adminView($o))->all()]]);
    }

    /** POST /prosight/operations/{id}/reconcile — perm approve. Autoridade C-2 resolve verifying/indeterminate. */
    public function reconcile(Request $request, int $id): JsonResponse
    {
        $op = $this->scopedOperation($request, $id);
        if (! $op) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }
        if (! $this->hasPerm($request->user(), $this->approvePermFor($op))) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $op = $this->ops->reconcile($op);

        return response()->json(['data' => $this->adminView($op)]);
    }

    /** POST /prosight/operations/{id}/resolve {resolution} — resolução HUMANA de contradicted/unresolved. */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $op = $this->scopedOperation($request, $id);
        if (! $op) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }
        if (! $this->hasPerm($request->user(), $this->approvePermFor($op))) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        // C5.4 — disposition SEM 'success' (autoridade física = C-2); reason OBRIGATÓRIO (governança/auditoria).
        $data = $request->validate(['resolution' => 'required|in:noop,failed', 'reason' => 'required|string|max:300']);
        $r = $this->ops->resolve($op, $request->user()->id, $data['resolution'], $data['reason']);
        if (! $r['ok']) {
            $code = in_array($r['error'], ['invalid_resolution', 'reason_required'], true) ? 422 : 409;

            return response()->json(['error' => $r['error']], $code);
        }

        return response()->json(['data' => $this->adminView($r['op'])]);
    }

    /**
     * GET /prosight/operations/{id}/audit — C5.4: reconstrução PONTA-A-PONTA read-only de uma operação (perm
     * view). Encadeia requester→aprovações→qualificação/artefato→target/membros→capability→from/to→claim→
     * execution_id→execution_committed→effect_started→coleta correlacionada→observado→decisão→resolução humana,
     * junto da timeline de connector_events correlacionados. SEM path/credencial/bytes de RPO (só hashes/ids).
     */
    public function audit(Request $request, int $id): JsonResponse
    {
        $op = $this->scopedOperation($request, $id);
        if (! $op) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }
        if (! $this->hasPerm($request->user(), 'prosight.operations.view')) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $op = $this->ops->reap($op); // materializa deadlines antes de auditar
        $events = \App\Models\ConnectorEvent::where('environment_id', $op->environment_id)
            ->where('meta->operation_id', $op->id)->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn ($e) => ['at' => $e->occurred_at?->toIso8601String(), 'event' => $e->event_type, 'outcome' => $e->outcome, 'detail' => $this->sanitize((string) ($e->detail ?? '')), 'meta' => $this->sanitizeMeta((array) $e->meta)])->all();

        return response()->json(['data' => ['operation' => $this->adminView($op), 'chain' => $this->auditChain($op), 'timeline' => $events]]);
    }

    /** Cadeia reconstruída (autoridade humana p/ known_good; C-2 p/ resultado físico). Só ids/hashes. */
    private function auditChain(ConnectorOperation $o): array
    {
        $s = $o->precondition_snapshot ?? [];
        $post = $o->postimage_snapshot ?? [];
        $isRpo = in_array($o->op_type, ['rpo_promote', 'rpo_rollback'], true);
        $correlated = is_array($post) && ($post['kind'] ?? null) !== null ? [
            'received_at' => $post['received_at'] ?? null, 'correlated' => (bool) ($post['correlated'] ?? false),
            'members' => $post['members'] ?? null, // {ref: {rpo_hash, up, publish_unit_id}} ou up/piid (lifecycle)
        ] : ($post ?: null);

        return [
            'op_type' => $o->op_type, 'kind' => $s['kind'] ?? null,
            'requester' => (int) $o->requested_by,
            'authority_model' => 'known_good=humano · resultado físico=C-2 observado · at-most-once (sem 2ª ação após ambiguidade)',
            'approvals' => [
                'required' => $s['required_approvals'] ?? null,
                'recorded' => $s['approvals'] ?? [],
                'last_approved_by' => $o->approved_by, 'approval_state' => $o->approval_state,
            ],
            'qualification' => $isRpo ? ($s['qualification'] ?? null) : null, // só rollback (autoridade nomeada)
            'target' => $isRpo ? ['target_id' => $o->rpo_target_id, 'members' => $s['members'] ?? [], 'publish_unit_id' => $s['publish_unit_id'] ?? null] : ['appserver_ref' => $o->appserver_ref],
            'capability' => $isRpo ? ['activation_mode' => $s['activation_mode'] ?? null, 'restart_strategy' => $s['restart_strategy'] ?? null] : null,
            'transition' => ['from_hash' => $s['from_hash'] ?? null, 'to_hash' => $s['to_hash'] ?? null, 'to_artifact_id' => $s['to_artifact_id'] ?? null],
            // C5.2b — evidência de restart: P1 congelado (pré) e P2 observado (pós) por membro; success exige P2≠P1.
            'restart' => (($s['activation_mode'] ?? null) === 'requires_restart') ? [
                'strategy' => $s['restart_strategy'] ?? null,
                'member_from_piid' => $s['member_piid'] ?? null, // P1 por membro
                'member_to_piid' => collect(($post['members'] ?? []))->map(fn ($m) => $m['process_instance_id'] ?? null)->all(), // P2 observado
            ] : null,
            'execution' => [
                'execution_id' => $o->execution_id,
                'claimed_at' => $o->claimed_at?->toIso8601String(),
                'execution_committed_at' => $o->execution_committed_at?->toIso8601String(),
                'effect_started_at' => $o->effect_started_at?->toIso8601String(),
                'publish_effect_started_at' => $o->publish_effect_started_at?->toIso8601String(),
                'restart_effect_started_at' => $o->restart_effect_started_at?->toIso8601String(),
            ],
            'correlated_collection' => $correlated,
            'decision' => [
                'status' => $o->status, 'reconciliation_state' => $o->reconciliation_state,
                'outcome_authority' => $o->outcome_authority, 'reconciled_at' => $o->reconciled_at?->toIso8601String(),
            ],
            'human_resolution' => $o->outcome_authority === 'human' ? ['resolved_by' => $o->resolved_by, 'at' => $o->reconciled_at?->toIso8601String()] : null,
        ];
    }

    /** Meta de evento sanitizada (allowlist de chaves seguras — nunca path/cred/bytes). */
    private function sanitizeMeta(array $meta): array
    {
        $allow = ['operation_id', 'execution_id', 'op_type', 'reason', 'reasons', 'approved_by', 'count', 'required', 'target_id', 'qualification_id', 'from', 'to', 'process_instance_id', 'reasons', 'override_reasons'];
        $out = [];
        foreach ($meta as $k => $v) {
            if (in_array($k, $allow, true) && (is_scalar($v) || is_array($v))) {
                $out[$k] = is_string($v) ? $this->sanitize($v) : $v;
            }
        }

        return $out;
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** Permissão do usuário (admin via '*'). Enforce granular por tipo/override no controller. */
    private function hasPerm($user, string $key): bool
    {
        $perms = \App\Services\PermissionService::for($user);

        return in_array('*', $perms, true) || in_array($key, $perms, true);
    }

    private function agentOperation(ConnectorAgent $agent, int $id): ?ConnectorOperation
    {
        $op = ConnectorOperation::whereKey($id)->first();

        return ($op && (int) $op->environment_id === (int) $agent->environment_id) ? $op : null;
    }

    private function scopedOperation(Request $request, int $id): ?ConnectorOperation
    {
        $op = ConnectorOperation::whereKey($id)->first();

        return ($op && $this->scope->canAccessCustomerId($request->user(), (int) $op->customer_id)) ? $op : null;
    }

    /**
     * Ao agente: só o necessário p/ executar. INCLUI execution_id. Sem path/PID/segredo/bytes. Para
     * rpo_promote inclui a IDENTIDADE do artefato (artifact_id + sha256) + activation_mode + publish_unit +
     * membros — o agente RESOLVE os bytes localmente (fonte on-prem) e RECOMPUTA o SHA-256 antes da barreira.
     * O Minutor NUNCA envia bytes nem path.
     */
    private function agentView(ConnectorOperation $o): array
    {
        $view = [
            'operation_id'            => $o->id,
            'op_type'                 => $o->op_type,
            'appserver_ref'           => $o->appserver_ref,
            'execution_id'            => $o->execution_id,
            'operational_deadline_at' => $o->operational_deadline_at?->toIso8601String(),
            'server_time'             => now()->timestamp,
        ];
        if (in_array($o->op_type, ['rpo_promote', 'rpo_rollback'], true)) {
            $s = $o->precondition_snapshot ?? [];
            $view['rpo'] = [
                'target_id'       => $o->rpo_target_id,
                'to_artifact_id'  => $s['to_artifact_id'] ?? null,
                'to_hash'         => $s['to_hash'] ?? null,   // sha256 esperado — agente RECOMPUTA localmente
                'from_hash'       => $s['from_hash'] ?? null, // pré-condição local: active_rpo_hash == from_hash
                'activation_mode' => $s['activation_mode'] ?? null,
                'publish_unit_id' => $s['publish_unit_id'] ?? null,
                'members'         => $s['members'] ?? [],
            ];
            // C5.2b — requires_restart: o agente precisa da estratégia (rolling) e da pré-imagem P1 por membro
            // (para orquestrar o rolling com readiness local e journalizar publish/restart). Sem bytes/path.
            if (($s['activation_mode'] ?? null) === 'requires_restart') {
                $view['rpo']['restart_strategy'] = $s['restart_strategy'] ?? null;
                $view['rpo']['member_from_piid'] = $s['member_piid'] ?? [];
            }
        }

        return $view;
    }

    /** À sessão: estado completo p/ auditoria. NÃO expõe execution_id como capability (só prefixo em meta). */
    private function adminView(ConnectorOperation $o): array
    {
        return [
            'id' => $o->id, 'environment_id' => (int) $o->environment_id, 'appserver_ref' => $o->appserver_ref,
            'op_type' => $o->op_type, 'status' => $o->status, 'approval_state' => $o->approval_state,
            'requested_by' => $o->requested_by, 'approved_by' => $o->approved_by, 'reason' => $o->reason,
            'agent_result' => $o->agent_result, 'agent_result_phase' => $o->agent_result_phase,
            'reconciliation_state' => $o->reconciliation_state, 'outcome_authority' => $o->outcome_authority,
            'resolution' => $o->resolution, 'resolved_by' => $o->resolved_by,
            'precondition_kind' => $o->precondition_kind, 'rpo_target_id' => $o->rpo_target_id,
            'emergency_override' => (bool) ($o->precondition_snapshot['emergency_override'] ?? false),
            'override_reasons' => $o->precondition_snapshot['override_reasons'] ?? [],
            'approvals_count' => is_array($o->precondition_snapshot['approvals'] ?? null) ? count($o->precondition_snapshot['approvals']) : null,
            'required_approvals' => $o->precondition_snapshot['required_approvals'] ?? null,
            'precondition_snapshot' => $o->precondition_snapshot, 'postimage_snapshot' => $o->postimage_snapshot,
            'claimed_at' => $o->claimed_at?->toIso8601String(), 'execution_committed_at' => $o->execution_committed_at?->toIso8601String(),
            'effect_started_at' => $o->effect_started_at?->toIso8601String(),
            'publish_effect_started_at' => $o->publish_effect_started_at?->toIso8601String(),
            'restart_effect_started_at' => $o->restart_effect_started_at?->toIso8601String(),
            'activation_mode' => $o->precondition_snapshot['activation_mode'] ?? null,
            'restart_strategy' => $o->precondition_snapshot['restart_strategy'] ?? null,
            'operational_deadline_at' => $o->operational_deadline_at?->toIso8601String(),
            'reconciled_at' => $o->reconciled_at?->toIso8601String(), 'created_at' => $o->created_at?->toIso8601String(),
        ];
    }

    private function sanitize(string $e): string
    {
        $e = mb_substr(trim($e), 0, 200);

        return preg_match('/secret|password|token|connection|\.ini|senha/i', $e) ? '[redacted]' : $e;
    }
}
