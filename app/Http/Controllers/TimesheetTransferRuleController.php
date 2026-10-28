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
        $rows = \App\Models\Timesheet::with(['user:id,name', 'project:id,code,name', 'sourceCustomer:id,name', 'sourceProject:id,code,name'])
            ->where('transfer_rule_id', $rule->id)
            ->when($dateFrom, fn ($q) => $q->whereDate($dateCol, '>=', $dateFrom))
            ->when($dateUntil, fn ($q) => $q->whereDate($dateCol, '<=', $dateUntil))
            ->orderByDesc('date')->orderByDesc('id')->limit(3000)->get();
        $items = $rows->map(fn ($t) => $this->rowView($t));

        return response()->json(['data' => ['total' => $items->count(), 'items' => $items->values()]]);
    }

    /** Mapeia uma linha de apontamento para os modais (origem cliente+projeto, ticket, observação limpa). */
    private function rowView(\App\Models\Timesheet $t): array
    {
        $srcProj = $t->relationLoaded('sourceProject') ? $t->sourceProject : null;
        return [
            'id'                 => $t->id,
            'date'               => optional($t->date)->toDateString(),
            'created_at'         => optional($t->created_at)->toIso8601String(),
            'hours'              => round((float) ($t->effort_minutes ?? 0) / 60, 2),
            'user'               => optional($t->user)->name,
            'project'            => $t->project ? trim(($t->project->code ? $t->project->code . ' · ' : '') . $t->project->name) : null,
            'origin'             => $t->transfer_source_customer_id
                ? (($t->relationLoaded('sourceCustomer') && $t->sourceCustomer) ? $t->sourceCustomer->name : '#' . $t->transfer_source_customer_id)
                : null,
            'origin_customer_id' => $t->transfer_source_customer_id ? (int) $t->transfer_source_customer_id : null,
            'origin_project'     => $srcProj ? trim(($srcProj->code ? $srcProj->code . ' · ' : '') . $srcProj->name) : null,
            'origin_project_id'  => $t->transfer_source_project_id ? (int) $t->transfer_source_project_id : null,
            'tracked'            => (bool) $t->transfer_rule_id,
            'observation'        => \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', html_entity_decode(preg_replace('/<[^>]*>/', ' ', (string) $t->observation), ENT_QUOTES | ENT_HTML5))), 3000),
            'ticket'             => $t->ticket ?: null,
            'origin_type'        => $t->origin,
            'via_integration'    => in_array($t->origin, ['webhook', 'movidesk', 'movidesk_fallback'], true),
            'date_locked'        => (bool) $t->date_locked,
            'manual_edit'        => (bool) $t->manual_project_edit,
        ];
    }

    /** GET /timesheet-transfer-rules/by-origin?customer_id=&date_from=&date_until= — apontamentos nos destinos
     *  cuja ORIGEM EFETIVA (COALESCE(transfer_source_customer_id, customer_id)) é o cliente informado. */
    public function byOrigin(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $customerId = (int) $request->query('customer_id');
        $dateFrom   = $request->query('date_from');
        $dateUntil  = $request->query('date_until');
        $targetIds  = TimesheetTransferRule::get(['target_project_id'])
            ->pluck('target_project_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if (! $customerId || empty($targetIds)) {
            return response()->json(['data' => ['total' => 0, 'items' => []]]);
        }
        $rows = \App\Models\Timesheet::with(['user:id,name', 'project:id,code,name', 'sourceCustomer:id,name', 'sourceProject:id,code,name'])
            ->where(function ($w) use ($targetIds) {
                $w->whereIn('project_id', $targetIds)->orWhereIn('real_project_id', $targetIds);
            })
            ->whereRaw('COALESCE(transfer_source_customer_id, customer_id) = ?', [$customerId])
            ->when($dateFrom, fn ($q) => $q->whereDate('date', '>=', $dateFrom))
            ->when($dateUntil, fn ($q) => $q->whereDate('date', '<=', $dateUntil))
            ->orderByDesc('date')->orderByDesc('id')->limit(3000)->get();
        $items = $rows->map(fn ($t) => $this->rowView($t));

        return response()->json(['data' => ['total' => $items->count(), 'items' => $items->values()]]);
    }

    /** POST /timesheet-transfer-rules/set-origin — INFORMA manualmente a origem (cliente/projeto) de 1+ apontamentos. */
    public function setOrigin(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $v = $request->validate([
            'timesheet_ids'      => ['required', 'array', 'min:1'],
            'timesheet_ids.*'    => ['integer'],
            'source_customer_id' => ['required', 'exists:customers,id'],
            'source_project_id'  => ['nullable', 'exists:projects,id'],
            'note'               => ['nullable', 'string', 'max:500'],
        ]);
        $actor  = $request->user()?->id;
        $srcCid = (int) $v['source_customer_id'];
        $srcPid = $v['source_project_id'] ?? null;

        // Mapa [projeto_destino][cliente_origem] => rule_id, p/ VINCULAR o apontamento à regra quando o
        // cliente informado bater com uma regra do projeto destino (aí ele passa a aparecer nos "Migrados"
        // daquela regra, não só no total por empresa).
        $ruleByTargetSource = [];
        foreach (TimesheetTransferRule::get(['id', 'source_customer_id', 'target_project_id']) as $r) {
            $ruleByTargetSource[(int) $r->target_project_id][(int) $r->source_customer_id] = (int) $r->id;
        }

        $rows = \App\Models\Timesheet::whereIn('id', $v['timesheet_ids'])->get(['id', 'project_id', 'real_project_id']);
        foreach ($rows as $ts) {
            $tgtP = isset($ruleByTargetSource[(int) $ts->project_id]) ? (int) $ts->project_id : (int) $ts->real_project_id;
            $ruleId = $ruleByTargetSource[$tgtP][$srcCid] ?? null;
            \App\Models\Timesheet::where('id', $ts->id)->update([
                'transfer_source_customer_id' => $srcCid,
                'transfer_source_project_id'  => $srcPid,
                'transfer_rule_id'            => $ruleId,
            ]);
            \App\Models\TimesheetOriginLog::create([
                'timesheet_id'       => $ts->id,
                'source_customer_id' => $srcCid,
                'source_project_id'  => $srcPid,
                'rule_id'            => $ruleId,
                'method'             => 'manual',
                'set_by'             => $actor,
                'note'               => $v['note'] ?? null,
            ]);
        }

        // Integração: espelha na Conecta a origem definida manualmente (tempo real, best-effort).
        $ids = $rows->pluck('id')->map(fn ($x) => (int) $x)->all();
        if (! empty($ids)) {
            foreach (array_chunk($ids, 200) as $batch) {
                try { \App\Jobs\PushMigratedHoursToConectaJob::dispatch($batch); } catch (\Throwable $e) { /* best-effort */ }
            }
        }

        return response()->json(['data' => ['updated' => $rows->count()]]);
    }

    /** GET /timesheet-transfer-rules/origin-logs?timesheet_id=&date_from=&date_until= — log de atribuição de origem. */
    public function originLogs(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $tsId      = $request->query('timesheet_id');
        $dateFrom  = $request->query('date_from');
        $dateUntil = $request->query('date_until');
        $rows = \App\Models\TimesheetOriginLog::with(['sourceCustomer:id,name', 'sourceProject:id,code,name', 'setBy:id,name'])
            ->when($tsId, fn ($x) => $x->where('timesheet_id', $tsId))
            ->when($dateFrom, fn ($x) => $x->whereDate('created_at', '>=', $dateFrom))
            ->when($dateUntil, fn ($x) => $x->whereDate('created_at', '<=', $dateUntil))
            ->orderByDesc('id')->limit(2000)->get();
        $items = $rows->map(fn ($l) => [
            'id'           => $l->id,
            'timesheet_id' => $l->timesheet_id,
            'customer'     => optional($l->sourceCustomer)->name,
            'project'      => $l->sourceProject ? trim(($l->sourceProject->code ? $l->sourceProject->code . ' · ' : '') . $l->sourceProject->name) : null,
            'rule_id'      => $l->rule_id,
            'ticket'       => $l->ticket,
            'method'       => $l->method,
            'set_by'       => optional($l->setBy)->name,
            'created_at'   => optional($l->created_at)->toIso8601String(),
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

        // "Não registrada" agora = sem origem rastreada E sem cliente atual (edge, ~nunca) — pois o
        // manual/nativo em destino já é atribuído ao cliente do projeto destino em totals().
        $rows = \App\Models\Timesheet::with(['user:id,name', 'project:id,code,name', 'sourceCustomer:id,name', 'sourceProject:id,code,name'])
            ->whereNull('transfer_source_customer_id')->whereNull('customer_id')
            ->where(function ($w) use ($targetIds) {
                $w->whereIn('project_id', $targetIds)->orWhereIn('real_project_id', $targetIds);
            })
            ->when($dateFrom, fn ($q) => $q->whereDate('date', '>=', $dateFrom))
            ->when($dateUntil, fn ($q) => $q->whereDate('date', '<=', $dateUntil))
            ->orderByDesc('date')->orderByDesc('id')->limit(3000)->get();
        $items = $rows->map(fn ($t) => $this->rowView($t));

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

        // Valor/hora de cobrança (R$) de cada projeto destino. Valor a receber = horas × hourly_rate.
        $rates = \App\Models\Project::whereIn('id', $targetIds)->pluck('hourly_rate', 'id');

        // per_rule: horas efetivamente RASTREADAS como migradas por esta regra (transfer_rule_id).
        // Base de data = a da própria regra. Fica 0 enquanto não houver migração/backfill rastreado.
        $perRule = [];
        foreach ($rules as $rule) {
            $dateCol = $rule->date_field === 'digitacao' ? 'created_at' : 'date';
            $q = \App\Models\Timesheet::where('transfer_rule_id', $rule->id);
            if ($dateFrom)  { $q->whereDate($dateCol, '>=', $dateFrom); }
            if ($dateUntil) { $q->whereDate($dateCol, '<=', $dateUntil); }
            $minutes = (int) $q->sum('effort_minutes');
            $rate = (float) ($rates[(int) $rule->target_project_id] ?? 0);
            $perRule[(int) $rule->id] = [
                'hours' => round($minutes / 60, 2),
                'count' => (int) $q->count(),
                'rate'  => round($rate, 2),
                'value' => round(($minutes / 60) * $rate, 2),
            ];
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

        // Origem EFETIVA = empresa rastreada OU, na falta (manual/nativo lançado direto no destino),
        // o CLIENTE ATUAL do apontamento — que é o do próprio projeto destino (segue a mesma lógica da
        // migração: o apontamento pertence à empresa do projeto destino). Só fica "não registrada" se
        // nem cliente houver (edge, ~nunca).
        $origemExpr = 'COALESCE(transfer_source_customer_id, customer_id)';
        $byCompany = empty($targetIds) ? collect() : $base()
            ->selectRaw("$origemExpr as cid, SUM(effort_minutes) as mins, COUNT(*) as cnt")
            ->groupByRaw($origemExpr)->get();
        $unMin = 0; $unCnt = 0;
        $valid = $byCompany->filter(function ($r) use (&$unMin, &$unCnt) {
            if ($r->cid === null) { $unMin += (int) $r->mins; $unCnt += (int) $r->cnt; return false; }
            return true;
        });
        // Valor a receber por EMPRESA de origem: horas × hourly_rate do projeto destino (project_id).
        // Query própria com join (prefixos explícitos — customer_id existe em timesheets E projects).
        $valByCid = [];
        if (! empty($targetIds)) {
            $vq = \Illuminate\Support\Facades\DB::table('timesheets as t')
                ->join('projects as dp', 'dp.id', '=', 't.project_id')
                ->whereNull('t.deleted_at')
                ->where(function ($w) use ($targetIds) {
                    $w->whereIn('t.project_id', $targetIds)->orWhereIn('t.real_project_id', $targetIds);
                });
            if ($dateFrom)  { $vq->whereDate('t.date', '>=', $dateFrom); }
            if ($dateUntil) { $vq->whereDate('t.date', '<=', $dateUntil); }
            foreach ($vq->selectRaw('COALESCE(t.transfer_source_customer_id, t.customer_id) as cid, SUM(t.effort_minutes/60.0 * COALESCE(dp.hourly_rate,0)) as val')
                ->groupByRaw('COALESCE(t.transfer_source_customer_id, t.customer_id)')->get() as $vr) {
                $valByCid[(int) $vr->cid] = round((float) $vr->val, 2);
            }
        }

        $names = \App\Models\Customer::whereIn('id', $valid->pluck('cid'))->pluck('name', 'id');
        $perCompany = $valid->map(fn ($r) => [
            'customer_id' => (int) $r->cid,
            'name'        => $names[$r->cid] ?? ('#' . $r->cid),
            'hours'       => round(((int) $r->mins) / 60, 2),
            'count'       => (int) $r->cnt,
            'value'       => $valByCid[(int) $r->cid] ?? 0,
        ])->sortByDesc('hours')->values();
        $grandMin = empty($targetIds) ? 0 : (int) $base()->sum('effort_minutes');
        $grandCnt = empty($targetIds) ? 0 : (int) $base()->count();
        $grandVal = round(array_sum($valByCid), 2);
        $blended  = $grandMin > 0 ? round($grandVal / ($grandMin / 60), 2) : 0;

        return response()->json(['data' => [
            'per_rule'          => $perRule,
            'per_company'       => $perCompany,
            'untracked'         => ['hours' => round($unMin / 60, 2), 'count' => $unCnt],
            'grand_total_hours' => round($grandMin / 60, 2),
            'grand_total_count' => $grandCnt,
            'grand_total_value' => $grandVal,
            'blended_rate'      => $blended,
        ]]);
    }

    /**
     * GET /timesheets/{id}/transfer-origin — origem (cliente/projeto) de UM apontamento, p/ exibir ao
     * abrir o apontamento (HD/timesheet). NÃO é admin-only (qualquer autenticado); retorna só nomes de
     * origem, nada sensível. null se o apontamento não tem origem de transferência registrada.
     */
    public function timesheetOrigin(int $id): JsonResponse
    {
        $ts = \App\Models\Timesheet::with(['sourceCustomer:id,name', 'sourceProject:id,code,name'])
            ->find($id);
        if (! $ts || ! $ts->transfer_source_customer_id) {
            return response()->json(['data' => null]);
        }
        $method = optional(\App\Models\TimesheetOriginLog::where('timesheet_id', $id)->orderByDesc('id')->first())->method;
        $proj = $ts->sourceProject;

        return response()->json(['data' => [
            'customer' => optional($ts->sourceCustomer)->name,
            'project'  => $proj ? trim(($proj->code ? $proj->code . ' · ' : '') . $proj->name) : null,
            'method'   => $method,
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
