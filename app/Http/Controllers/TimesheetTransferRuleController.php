<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TimesheetTransferRule;
use App\Services\TimesheetTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transferência de Apontamentos — CRUD de regras cliente→projeto por janela de data + aplicação
 * retroativa. Admin-only (altera lançamentos/faturamento). Novos apontamentos são movidos pelo
 * hook no Timesheet; aqui tratamos as regras e a aplicação nos EXISTENTES.
 */
class TimesheetTransferRuleController extends Controller
{
    public function __construct(private TimesheetTransferService $service)
    {
    }

    /** Gate admin-only (altera lançamentos/faturamento). Chamado no início de cada ação. */
    private function ensureAdmin(): void
    {
        abort_unless(optional(auth()->user())->isAdmin(), 403, 'Apenas administradores.');
    }

    public function index(): JsonResponse
    {
        $this->ensureAdmin();
        $rows = TimesheetTransferRule::with(['sourceCustomer:id,name', 'targetProject:id,name,code,customer_id'])
            ->orderByDesc('active')->orderByDesc('id')->get()
            ->map(fn (TimesheetTransferRule $r) => $this->view($r));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $v = $this->validateRule($request);
        $rule = new TimesheetTransferRule($v);
        $rule->company_id = optional(Project::find($v['target_project_id']))->company_id;
        $rule->created_by = $request->user()?->id;
        $rule->save();

        // Aplicação retroativa opcional ao criar.
        $applied = null;
        if ($request->boolean('apply_now')) {
            $applied = $this->service->applyRule($rule, false, $request->user()?->id);
        }

        return response()->json(['data' => $this->view($rule->fresh(['sourceCustomer', 'targetProject'])), 'applied' => $applied], 201);
    }

    public function update(Request $request, TimesheetTransferRule $rule): JsonResponse
    {
        $this->ensureAdmin();
        $v = $this->validateRule($request);
        $rule->fill($v);
        $rule->company_id = optional(Project::find($v['target_project_id']))->company_id;
        $rule->save();

        $applied = null;
        if ($request->boolean('apply_now')) {
            $applied = $this->service->applyRule($rule, false, $request->user()?->id);
        }

        return response()->json(['data' => $this->view($rule->fresh(['sourceCustomer', 'targetProject'])), 'applied' => $applied]);
    }

    public function destroy(TimesheetTransferRule $rule): JsonResponse
    {
        $this->ensureAdmin();
        $rule->delete();
        return response()->json(['data' => ['deleted' => true]]);
    }

    /** POST /timesheet-transfer-rules/{rule}/apply — aplica a regra nos apontamentos existentes. */
    public function apply(Request $request, TimesheetTransferRule $rule): JsonResponse
    {
        $this->ensureAdmin();
        $result = $this->service->applyRule($rule, $request->boolean('dry_run'), $request->user()?->id);
        return response()->json(['data' => $result]);
    }

    /** POST /timesheet-transfer-rules/preview — conta quantos apontamentos moveriam (sem salvar). */
    public function preview(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $v = $this->validateRule($request);
        $rule = new TimesheetTransferRule($v); // transiente, não salvo
        $result = $this->service->applyRule($rule, true);
        return response()->json(['data' => $result]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function validateRule(Request $request): array
    {
        return $request->validate([
            'source_customer_id' => ['required', 'exists:customers,id'],
            'target_project_id'  => ['required', 'exists:projects,id'],
            'date_field'         => ['required', 'in:servico,digitacao'],
            'date_from'          => ['nullable', 'date'],
            'date_until'         => ['nullable', 'date', 'after_or_equal:date_from'],
            'active'             => ['sometimes', 'boolean'],
            'override_manual'    => ['sometimes', 'boolean'],
        ]);
    }

    private function view(TimesheetTransferRule $r): array
    {
        return [
            'id'                  => $r->id,
            'source_customer_id'  => $r->source_customer_id,
            'source_customer'     => optional($r->sourceCustomer)->name,
            'target_project_id'   => $r->target_project_id,
            'target_project'      => optional($r->targetProject)->name,
            'target_project_code' => optional($r->targetProject)->code,
            'date_field'          => $r->date_field,
            'date_from'           => optional($r->date_from)->toDateString(),
            'date_until'          => optional($r->date_until)->toDateString(),
            'active'              => (bool) $r->active,
            'override_manual'     => (bool) $r->override_manual,
            'created_at'          => optional($r->created_at)->toIso8601String(),
        ];
    }
}
