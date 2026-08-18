<?php

namespace App\Http\Controllers;

use App\Models\SourceDoc;
use App\Models\SourceDocActionLog;
use App\Models\SourceDocCostApproval;
use App\SourceCode\Cost\CostSettingsResolver;
use App\SourceCode\Cost\SourceCostGovernor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central de Fontes — Frente A. Fila "Aprovações de IA". INTERNO ERPSERV (gate de permissão nas rotas).
 * A decisão altera o estado de GOVERNANÇA (eleva teto / libera passo / encerra / rejeita); a re-execução
 * do passo é feita pelo fluxo normal de reprocess/topup, que então passa no governor. Tudo auditado.
 */
class SourceDocCostApprovalController extends Controller
{
    public function __construct(
        private SourceCostGovernor $governor,
        private CostSettingsResolver $resolver,
    ) {
    }

    /** GET /source-docs/cost-approvals?status=pending&customer_id=&repository= */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'pending');
        $q = SourceDocCostApproval::query()
            ->join('source_docs', 'source_docs.id', '=', 'source_doc_cost_approvals.source_doc_id')
            ->leftJoin('customers', 'customers.id', '=', 'source_docs.customer_id')
            ->when($status !== 'all', fn ($qq) => $qq->where('source_doc_cost_approvals.status', $status))
            ->when($request->filled('customer_id'), fn ($qq) => $qq->where('source_docs.customer_id', (int) $request->query('customer_id')))
            ->when($request->filled('repository'), fn ($qq) => $qq->where('source_docs.repository', (string) $request->query('repository')))
            ->orderByDesc('source_doc_cost_approvals.created_at')
            ->limit(200)
            ->get([
                'source_doc_cost_approvals.*',
                'source_docs.filename', 'source_docs.path', 'source_docs.repository',
                'source_docs.customer_id', 'customers.name as customer_name',
            ]);

        return response()->json(['data' => $q]);
    }

    /** GET /source-docs/cost-approvals/{id} — detalhe com config efetiva e origem. */
    public function show(int $id): JsonResponse
    {
        $a = SourceDocCostApproval::query()->find($id);
        if (! $a) {
            return response()->json(['message' => 'Solicitação não encontrada.'], 404);
        }
        $doc = SourceDoc::with('customer:id,name', 'currentVersion:id')->find($a->source_doc_id);
        $settings = $doc ? $this->resolver->for($doc)->toArray() : null;

        return response()->json(['data' => [
            'approval' => $a,
            'source' => $doc ? [
                'id' => $doc->id, 'filename' => $doc->filename, 'path' => $doc->path,
                'repository' => $doc->repository, 'customer' => ['id' => $doc->customer_id, 'name' => $doc->customer?->name],
            ] : null,
            'settings' => $settings, // inclui operational_limit_usd, max_approved_cost_usd, source_label ("Herdado de...")
        ]]);
    }

    /** POST /cost-approvals/{id}/approve-step — libera só o próximo passo. */
    public function approveStep(int $id, Request $request): JsonResponse
    {
        return $this->guard($id, function (SourceDocCostApproval $a) use ($request) {
            $applied = $this->governor->approveStep($a, (int) $request->user()?->id);
            $this->audit($a, 'approve_step', $request->user()?->id, ['hard_limit_usd' => $applied]);
            return ['action' => 'approved_step', 'applied_limit_usd' => $applied];
        });
    }

    /** POST /cost-approvals/{id}/approve-limit {new_limit_usd} — eleva o teto desta fonte (≤ max_approved). */
    public function approveLimit(int $id, Request $request): JsonResponse
    {
        $data = $request->validate(['new_limit_usd' => ['required', 'numeric', 'gt:0']]);
        return $this->guard($id, function (SourceDocCostApproval $a) use ($request, $data) {
            $applied = $this->governor->approveLimit($a, (float) $data['new_limit_usd'], (int) $request->user()?->id);
            $this->audit($a, 'approve_limit', $request->user()?->id, ['hard_limit_usd' => $applied]);
            return ['action' => 'approved_limit', 'applied_limit_usd' => $applied];
        });
    }

    /** POST /cost-approvals/{id}/close-partial — encerra a fonte como parcial. */
    public function closePartial(int $id, Request $request): JsonResponse
    {
        return $this->guard($id, function (SourceDocCostApproval $a) use ($request) {
            $this->governor->decide($a, 'closed_partial', (int) $request->user()?->id);
            $this->audit($a, 'close_partial', $request->user()?->id);
            return ['action' => 'closed_partial'];
        });
    }

    /** POST /cost-approvals/{id}/reject — rejeita a solicitação. */
    public function reject(int $id, Request $request): JsonResponse
    {
        return $this->guard($id, function (SourceDocCostApproval $a) use ($request) {
            $this->governor->decide($a, 'rejected', (int) $request->user()?->id);
            $this->audit($a, 'reject', $request->user()?->id);
            return ['action' => 'rejected'];
        });
    }

    /** Carrega a solicitação, garante que está pendente e executa a decisão. */
    private function guard(int $id, \Closure $fn): JsonResponse
    {
        $a = SourceDocCostApproval::query()->find($id);
        if (! $a) {
            return response()->json(['message' => 'Solicitação não encontrada.'], 404);
        }
        if ($a->status !== SourceDocCostApproval::OPEN) {
            return response()->json(['message' => 'Solicitação já decidida.', 'status' => $a->status], 409);
        }
        $result = $fn($a);
        return response()->json(['data' => array_merge($result, ['approval_id' => $a->id, 'status' => $a->fresh()->status])]);
    }

    private function audit(SourceDocCostApproval $a, string $action, ?int $userId, array $params = []): void
    {
        SourceDocActionLog::create([
            'source_doc_id' => $a->source_doc_id, 'version_id' => $a->version_id,
            'action' => 'cost_approval_' . $action, 'layer' => 'semantic', 'actor_user_id' => $userId,
            'status' => 'ok', 'params' => SourceDocActionLog::sanitize($params),
        ]);
    }
}
