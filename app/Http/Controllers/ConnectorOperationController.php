<?php

namespace App\Http\Controllers;

use App\Connector\ConnectorOperationService;
use App\Models\ConnectorAgent;
use App\Models\ConnectorOperation;
use App\Models\EnvEnvironment;
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

    /** POST /connector/operations/{id}/ack {execution_id} — barreira claimed→execution_committed. */
    public function ack(Request $request, int $id): JsonResponse
    {
        $agent = $request->attributes->get('connector_agent');
        $data = $request->validate(['execution_id' => 'required|uuid']);
        $op = $this->agentOperation($agent, $id);
        if (! $op) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }
        $r = $this->ops->ack($agent, $op, $data['execution_id']);

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
        $permByType = ['start' => 'prosight.operations.start', 'stop' => 'prosight.operations.stop'];
        $need = $permByType[$data['op_type']] ?? null;
        if ($need === null) {
            return response()->json(['error' => 'op_type_not_allowed'], 422);
        }
        if (! $this->hasPerm($request->user(), $need)) {
            return response()->json(['error' => 'forbidden'], 403); // perm granular por tipo (não herda execute)
        }
        $hasOverride = $this->hasPerm($request->user(), 'prosight.operations.stop.override');
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

    /** POST /prosight/operations/{id}/approve — perm approve, requester ≠ approver. */
    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, 'approve');
    }

    /** POST /prosight/operations/{id}/reject — perm approve. */
    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, 'reject');
    }

    private function decide(Request $request, int $id, string $action): JsonResponse
    {
        $op = $this->scopedOperation($request, $id);
        if (! $op) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }
        $r = $action === 'approve'
            ? $this->ops->approve($op, $request->user()->id, $this->hasPerm($request->user(), 'prosight.operations.stop.override'))
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
        $need = ['start' => 'prosight.operations.start', 'stop' => 'prosight.operations.stop'][$op->op_type] ?? null;
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
        $op = $this->ops->reconcile($op);

        return response()->json(['data' => $this->adminView($op)]);
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

    /** Ao agente: só o necessário p/ executar. INCLUI execution_id. Sem path/PID/segredo. */
    private function agentView(ConnectorOperation $o): array
    {
        return [
            'operation_id'            => $o->id,
            'op_type'                 => $o->op_type,
            'appserver_ref'           => $o->appserver_ref,
            'execution_id'            => $o->execution_id,
            'operational_deadline_at' => $o->operational_deadline_at?->toIso8601String(),
            'server_time'             => now()->timestamp,
        ];
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
            'precondition_kind' => $o->precondition_kind,
            'emergency_override' => (bool) ($o->precondition_snapshot['emergency_override'] ?? false),
            'override_reasons' => $o->precondition_snapshot['override_reasons'] ?? [],
            'precondition_snapshot' => $o->precondition_snapshot, 'postimage_snapshot' => $o->postimage_snapshot,
            'claimed_at' => $o->claimed_at?->toIso8601String(), 'execution_committed_at' => $o->execution_committed_at?->toIso8601String(),
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
