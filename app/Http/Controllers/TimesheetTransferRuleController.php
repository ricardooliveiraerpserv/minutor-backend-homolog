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

    /** GET /timesheet-transfer-rules/{rule}/applied — apontamentos JÁ migrados por esta regra. */
    public function applied(Request $request, TimesheetTransferRule $rule): JsonResponse
    {
        $this->ensureAdmin();
        $dateFrom  = $request->query('date_from');
        $dateUntil = $request->query('date_until');

        // Escopo desta regra = SOMENTE linhas RASTREADAS como migradas por ela (transfer_rule_id = regra).
        // Apontamentos no destino SEM origem registrada (transfer_rule_id NULL) NÃO entram aqui — não são
        // atribuíveis a uma empresa/regra específica (apareceriam repetidos em toda regra que compartilha o
        // destino). Eles vivem só no consolidado "não registrada" (endpoint untracked). Filtro = base da regra.
        $dateCol  = $rule->date_field === 'digitacao' ? 'created_at' : 'date';
        $q = \App\Models\Timesheet::with(['user:id,name', 'project:id,code,name'])
            ->where('transfer_rule_id', $rule->id);
        if ($dateFrom)  { $q->whereDate($dateCol, '>=', $dateFrom); }
        if ($dateUntil) { $q->whereDate($dateCol, '<=', $dateUntil); }
        $rows = $q->orderByDesc('date')->orderByDesc('id')->limit(3000)->get();
        $srcNames = \App\Models\Customer::whereIn('id', $rows->pluck('transfer_source_customer_id')->filter()->unique())
            ->pluck('name', 'id');
        $items = $rows->map(fn ($t) => [
            'id'          => $t->id,
            'date'        => optional($t->date)->toDateString(),
            'created_at'  => optional($t->created_at)->toIso8601String(),
            'hours'       => round((float) ($t->effort_minutes ?? 0) / 60, 2),
            'user'        => optional($t->user)->name,
            'project'     => $t->project ? trim(($t->project->code ? $t->project->code . ' · ' : '') . $t->project->name) : null,
            'origin'      => $t->transfer_source_customer_id ? ($srcNames[$t->transfer_source_customer_id] ?? ('#' . $t->transfer_source_customer_id)) : null,
            'tracked'     => (bool) $t->transfer_rule_id,
            'observation' => \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $t->observation))), 800),
            'date_locked' => (bool) $t->date_locked,
            'manual_edit' => (bool) $t->manual_project_edit,
        ]);

        return response()->json(['data' => ['total' => $items->count(), 'items' => $items->values()]]);
    }

    /**
     * GET /timesheet-transfer-rules/untracked — apontamentos nos projetos destino SEM origem registrada
     * (transfer_rule_id NULL): migrados antes do rastreio ou em destinos com 2+ regras (não atribuíveis a
     * uma empresa). Consolidado único — NÃO repete por regra. Filtro pela data de serviço.
     */
    public function untracked(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $dateFrom  = $request->query('date_from');
        $dateUntil = $request->query('date_until');

        $targetIds = TimesheetTransferRule::get(['target_project_id'])
            ->pluck('target_project_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if (empty($targetIds)) {
            return response()->json(['data' => ['total' => 0, 'items' => []]]);
        }

        $q = \App\Models\Timesheet::with(['user:id,name', 'project:id,code,name'])
            ->whereNull('transfer_rule_id')
            ->where(function ($w) use ($targetIds) {
                $w->whereIn('project_id', $targetIds)->orWhereIn('real_project_id', $targetIds);
            });
        if ($dateFrom)  { $q->whereDate('date', '>=', $dateFrom); }
        if ($dateUntil) { $q->whereDate('date', '<=', $dateUntil); }
        $items = $q->orderByDesc('date')->orderByDesc('id')->limit(3000)->get()->map(fn ($t) => [
            'id'          => $t->id,
            'date'        => optional($t->date)->toDateString(),
            'created_at'  => optional($t->created_at)->toIso8601String(),
            'hours'       => round((float) ($t->effort_minutes ?? 0) / 60, 2),
            'user'        => optional($t->user)->name,
            'project'     => $t->project ? trim(($t->project->code ? $t->project->code . ' · ' : '') . $t->project->name) : null,
            'observation' => \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $t->observation))), 800),
            'date_locked' => (bool) $t->date_locked,
            'manual_edit' => (bool) $t->manual_project_edit,
        ]);

        return response()->json(['data' => ['total' => $items->count(), 'items' => $items->values()]]);
    }

    /** GET /timesheet-transfer-rules/totals?date_from=&date_until= — total de horas por regra + geral. */
    public function totals(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $dateFrom  = $request->query('date_from');
        $dateUntil = $request->query('date_until');

        $rules = TimesheetTransferRule::get(['id', 'source_customer_id', 'target_project_id', 'date_field']);
        $targetIds = $rules->pluck('target_project_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        // per_rule: horas efetivamente RASTREADAS como migradas por esta regra (transfer_rule_id).
        // Base de data = a da própria regra. Fica 0 enquanto não houver migração/backfill rastreado.
        $perRule = [];
        foreach ($rules as $rule) {
            $dateCol = $rule->date_field === 'digitacao' ? 'created_at' : 'date';
            $q = \App\Models\Timesheet::where('transfer_rule_id', $rule->id);
            if ($dateFrom)  { $q->whereDate($dateCol, '>=', $dateFrom); }
            if ($dateUntil) { $q->whereDate($dateCol, '<=', $dateUntil); }
            $minutes = (int) $q->sum('effort_minutes');
            $perRule[(int) $rule->id] = ['hours' => round($minutes / 60, 2), 'count' => (int) $q->count()];
        }

        // Universo = apontamentos que ESTÃO HOJE nos projetos destino das regras (linhas distintas —
        // um OR entre project_id/real_project_id não duplica a linha). Filtro cross-regra pela data de
        // SERVIÇO (date). Particiona por origem RASTREADA (transfer_source_customer_id) + bucket
        // "não registrada" (NULL = migrados antes do rastreio ou não atribuíveis). Soma das partições = geral.
        $base = function () use ($targetIds, $dateFrom, $dateUntil) {
            $q = \App\Models\Timesheet::query()->where(function ($w) use ($targetIds) {
                $w->whereIn('project_id', $targetIds)->orWhereIn('real_project_id', $targetIds);
            });
            if ($dateFrom)  { $q->whereDate('date', '>=', $dateFrom); }
            if ($dateUntil) { $q->whereDate('date', '<=', $dateUntil); }
            return $q;
        };

        $byCompany = empty($targetIds) ? collect() : $base()
            ->whereNotNull('transfer_source_customer_id')
            ->selectRaw('transfer_source_customer_id as cid, SUM(effort_minutes) as mins, COUNT(*) as cnt')
            ->groupBy('transfer_source_customer_id')->get();
        $names = \App\Models\Customer::whereIn('id', $byCompany->pluck('cid'))->pluck('name', 'id');
        $perCompany = $byCompany->map(fn ($r) => [
            'customer_id' => (int) $r->cid,
            'name'        => $names[$r->cid] ?? ('#' . $r->cid),
            'hours'       => round(((int) $r->mins) / 60, 2),
            'count'       => (int) $r->cnt,
        ])->sortByDesc('hours')->values();

        $unMin = empty($targetIds) ? 0 : (int) $base()->whereNull('transfer_source_customer_id')->sum('effort_minutes');
        $unCnt = empty($targetIds) ? 0 : (int) $base()->whereNull('transfer_source_customer_id')->count();
        $grandMin = empty($targetIds) ? 0 : (int) $base()->sum('effort_minutes');
        $grandCnt = empty($targetIds) ? 0 : (int) $base()->count();

        return response()->json(['data' => [
            'per_rule'          => $perRule,
            'per_company'       => $perCompany,
            'untracked'         => ['hours' => round($unMin / 60, 2), 'count' => $unCnt],
            'grand_total_hours' => round($grandMin / 60, 2),
            'grand_total_count' => $grandCnt,
        ]]);
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
