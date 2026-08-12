<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectBaseline;
use App\Models\StageBaselineItem;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ConsultorCostService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cronograma — EVM (Earned Value Management) em HORAS.
 *
 * Fase 1/2 do dashboard de indicadores: usa a LINHA DE BASE congelada
 * ({@see ProjectBaseline}) como referência de plano e os apontamentos como custo real,
 * tudo em HORAS (aderente ao Minutor — sem exigir orçamento em R$).
 *
 *   PV  = horas planejadas (baseline) distribuídas no tempo, acumuladas até a data
 *   EV  = horas planejadas (baseline) das atividades JÁ concluídas
 *   AC  = horas apontadas (approved + released)
 *   SPI = EV/PV   SV = EV-PV   CPI = EV/AC   CV = EV-AC
 *   BAC = Σ horas planejadas   EAC = BAC/CPI   ETC = EAC-AC   VAC = BAC-EAC
 */
class ProjectEvmController extends Controller
{
    /** Congela a linha de base atual do cronograma (snapshot imutável de datas + horas + custo planejado). */
    public function freeze(Project $project, Request $request): JsonResponse
    {
        $baseline = $this->snapshotBaseline($project, trim((string) $request->input('label', '')) ?: null, $request->input('notes'));

        return response()->json([
            'message'  => 'Linha de base congelada.',
            'baseline' => $this->baselineMeta($baseline->fresh()),
        ], 201);
    }

    /** Congela a linha de base de TODOS os projetos do filtro que ainda NÃO têm baseline (só os que têm cronograma). */
    public function freezeMissing(Request $request): JsonResponse
    {
        $ids = $this->portfolioBaseQuery($request)->pluck('id')->all();
        if (empty($ids)) return response()->json(['frozen' => 0]);

        $withBase = ProjectBaseline::whereIn('project_id', $ids)->where('is_current', true)->pluck('project_id')->all();
        $missing  = array_values(array_diff($ids, $withBase));

        $count = 0;
        Project::whereIn('id', $missing)->whereHas('stages')->chunkById(50, function ($chunk) use (&$count) {
            foreach ($chunk as $p) { $this->snapshotBaseline($p); $count++; }
        });

        return response()->json(['frozen' => $count]);
    }

    /** Núcleo do congelamento: monta a baseline (datas + horas + custo planejado) de um projeto. */
    private function snapshotBaseline(Project $project, ?string $label = null, ?string $notes = null): ProjectBaseline
    {
        $label = $label ?: 'Linha de base '.now()->format('d/m/Y');
        $comp  = now()->format('Y-m');                 // competência do congelamento (custo/hora vigente)
        $costSvc = app(ConsultorCostService::class);

        $stages = $project->stages()->with('deliveries')->get();
        $userIds = $stages->pluck('responsible_user_id')
            ->merge($stages->flatMap(fn ($s) => $s->deliveries->pluck('responsible_user_id')))
            ->filter()->unique()->values();
        $users = User::with('partner')->whereIn('id', $userIds->all() ?: [0])->get()->keyBy('id');
        $rateFor = fn ($uid) => ($uid && $users->has($uid)) ? $costSvc->hourlyCost($users->get($uid), $comp) : 0.0;

        return DB::transaction(function () use ($project, $label, $notes, $stages, $rateFor) {
            ProjectBaseline::where('project_id', $project->id)->where('is_current', true)->update(['is_current' => false]);

            $baseline = ProjectBaseline::create([
                'project_id'          => $project->id,
                'label'               => $label,
                'frozen_at'           => now(),
                'frozen_by'           => auth()->id(),
                'planned_hours_total' => 0,
                'planned_cost_total'  => 0,
                'notes'               => $notes,
                'is_current'          => true,
            ]);

            $totalH = 0.0; $totalC = 0.0;
            $mk = function (array $attrs, float $hours, float $rate) use ($baseline, &$totalH, &$totalC) {
                $cost = round($hours * $rate, 2);
                StageBaselineItem::create($attrs + ['project_baseline_id' => $baseline->id, 'planned_hours' => $hours, 'planned_cost' => $cost]);
                $totalH += $hours; $totalC += $cost;
            };

            foreach ($stages as $stage) {
                $deliveries = $stage->deliveries;
                if ($deliveries->isEmpty()) {
                    $mk([
                        'stage_id'          => $stage->id,
                        'stage_delivery_id' => null,
                        'title'             => $stage->name,
                        'planned_start_at'  => $stage->stage_start_at,
                        'planned_end_at'    => $stage->expected_end_date,
                    ], (float) $stage->hours_planned, $rateFor($stage->responsible_user_id));
                    continue;
                }
                foreach ($deliveries as $d) {
                    $mk([
                        'stage_id'          => $stage->id,
                        'stage_delivery_id' => $d->id,
                        'title'             => $d->title,
                        'planned_start_at'  => $d->planned_start_at,
                        'planned_end_at'    => optional($d->plannedEndDate())->toDateString(),
                    ], (float) $d->hours_planned, $rateFor($d->responsible_user_id));
                }
            }

            $baseline->update(['planned_hours_total' => round($totalH, 2), 'planned_cost_total' => round($totalC, 2)]);
            return $baseline;
        });
    }

    /** Descongela: remove a linha de base current (desfaz o congelamento). Itens caem por cascade. */
    public function unfreeze(Project $project): JsonResponse
    {
        ProjectBaseline::where('project_id', $project->id)->where('is_current', true)
            ->get()->each(fn (ProjectBaseline $b) => $b->delete());

        return response()->json(['message' => 'Linha de base removida.']);
    }

    /** Indicadores EVM (em horas) + curva-S do projeto, a partir da baseline current. */
    public function evm(Project $project, Request $request): JsonResponse
    {
        $asOf = $request->filled('date')
            ? Carbon::parse($request->input('date'))->endOfDay()
            : now()->endOfDay();

        $baseline = ProjectBaseline::where('project_id', $project->id)
            ->where('is_current', true)->latest('frozen_at')->first();

        if (! $baseline) {
            return response()->json([
                'has_baseline' => false,
                'message'      => 'Congele a linha de base para habilitar os indicadores de EVM.',
            ]);
        }

        $items = StageBaselineItem::where('project_baseline_id', $baseline->id)->get();

        // Datas reais de conclusão das atividades congeladas (para o EV no tempo).
        $deliveryIds = $items->pluck('stage_delivery_id')->filter()->all();
        $doneAt = DB::table('stage_deliveries')
            ->whereIn('id', $deliveryIds ?: [0])->where('status', 'done')->whereNotNull('completed_at')
            ->pluck('completed_at', 'id'); // [id => datetime]

        $stageEndAt = DB::table('project_stages')
            ->whereIn('id', $items->whereNull('stage_delivery_id')->pluck('stage_id')->filter()->all() ?: [0])
            ->where('status', 'done')->whereNotNull('actual_end_at')
            ->pluck('actual_end_at', 'id');

        // Data de conclusão real de cada item (null = não concluído).
        $itemDoneAt = function (StageBaselineItem $it) use ($doneAt, $stageEndAt): ?Carbon {
            if ($it->stage_delivery_id && isset($doneAt[$it->stage_delivery_id])) return Carbon::parse($doneAt[$it->stage_delivery_id]);
            if (! $it->stage_delivery_id && $it->stage_id && isset($stageEndAt[$it->stage_id])) return Carbon::parse($stageEndAt[$it->stage_id]);
            return null;
        };

        // Apontamentos do projeto (approved + released) por dia — para AC no tempo.
        $acRows = DB::table('timesheets')
            ->where('project_id', $project->id)->whereNull('deleted_at')
            ->whereIn('status', [Timesheet::STATUS_APPROVED, Timesheet::STATUS_RELEASED])
            ->groupBy('date')->selectRaw('date, COALESCE(SUM(effort_minutes),0)/60.0 AS h')
            ->get()->map(fn ($r) => ['date' => Carbon::parse($r->date)->endOfDay(), 'h' => (float) $r->h]);

        $hasCost = (float) $baseline->planned_cost_total > 0;
        $bac  = (float) $baseline->planned_hours_total;
        $bacC = (float) $baseline->planned_cost_total;

        // PV acumulado até $date: valor de cada item (horas OU custo) distribuído linearmente em [start, end].
        $pvGen = function (Carbon $date, callable $val) use ($items): float {
            $sum = 0.0;
            foreach ($items as $it) {
                $v = (float) $val($it);
                if ($v <= 0) continue;
                $s = $it->planned_start_at; $e = $it->planned_end_at;
                if (! $e) continue;                 // sem fim planejado não entra no PV
                $end = Carbon::parse($e)->endOfDay();
                if (! $s) { $sum += $date->gte($end) ? $v : 0.0; continue; } // sem início: degrau no fim
                $start = Carbon::parse($s)->startOfDay();
                if ($date->lte($start)) continue;
                if ($date->gte($end)) { $sum += $v; continue; }
                $span = max(1, $start->diffInDays($end));
                $sum += $v * min(1.0, max(0.0, $start->diffInDays($date) / $span));
            }
            return round($sum, 2);
        };

        // EV acumulado até $date: valor dos itens concluídos até a data.
        $evGen = function (Carbon $date, callable $val) use ($items, $itemDoneAt): float {
            $sum = 0.0;
            foreach ($items as $it) {
                $done = $itemDoneAt($it);
                if ($done && $done->lte($date)) $sum += (float) $val($it);
            }
            return round($sum, 2);
        };

        $hoursVal = fn (StageBaselineItem $it) => (float) $it->planned_hours;
        $costValF = fn (StageBaselineItem $it) => (float) $it->planned_cost;

        $acAt = function (Carbon $date) use ($acRows): float {
            return round($acRows->filter(fn ($r) => $r['date']->lte($date))->sum('h'), 2);
        };

        // AC em R$ por dia: horas apontadas × custo/hora do consultor NA competência do apontamento (motor Rentabilidade).
        $acCostRows = collect();
        if ($hasCost) {
            $costSvc = app(ConsultorCostService::class);
            $raw = DB::table('timesheets')
                ->where('project_id', $project->id)->whereNull('deleted_at')
                ->whereIn('status', [Timesheet::STATUS_APPROVED, Timesheet::STATUS_RELEASED])
                ->groupBy('user_id', 'date')
                ->selectRaw('user_id, date, COALESCE(SUM(effort_minutes),0)/60.0 AS h')->get();
            $tsUsers = User::with('partner')->whereIn('id', $raw->pluck('user_id')->filter()->unique()->all() ?: [0])->get()->keyBy('id');
            $acCostRows = $raw->map(function ($r) use ($tsUsers, $costSvc) {
                $d = Carbon::parse($r->date);
                $rate = ($r->user_id && $tsUsers->has($r->user_id)) ? $costSvc->hourlyCost($tsUsers->get($r->user_id), $d->format('Y-m')) : 0.0;
                return ['date' => $d->endOfDay(), 'c' => round(((float) $r->h) * $rate, 2)];
            });
        }
        $acCostAt = fn (Carbon $date) => round($acCostRows->filter(fn ($r) => $r['date']->lte($date))->sum('c'), 2);

        $pv = $pvGen($asOf, $hoursVal); $ev = $evGen($asOf, $hoursVal); $ac = $acAt($asOf);
        $spi = $pv > 0 ? round($ev / $pv, 3) : null;
        $cpi = $ac > 0 ? round($ev / $ac, 3) : null;
        $eac = ($cpi && $cpi > 0) ? round($bac / $cpi, 2) : null;

        // EVM em R$ (Fase 3) — só quando a baseline tem custo planejado congelado.
        $cost = null;
        if ($hasCost) {
            $pvC = $pvGen($asOf, $costValF); $evC = $evGen($asOf, $costValF); $acC = $acCostAt($asOf);
            $cpiC = $acC > 0 ? round($evC / $acC, 3) : null;
            $eacC = ($cpiC && $cpiC > 0) ? round($bacC / $cpiC, 2) : null;
            $cost = [
                'bac' => round($bacC, 2), 'pv' => $pvC, 'ev' => $evC, 'ac' => $acC,
                'sv' => round($evC - $pvC, 2), 'cv' => round($evC - $acC, 2),
                'spi' => $pvC > 0 ? round($evC / $pvC, 3) : null, 'cpi' => $cpiC,
                'eac' => $eacC, 'etc' => $eacC !== null ? round($eacC - $acC, 2) : null,
                'vac' => $eacC !== null ? round($bacC - $eacC, 2) : null,
            ];
        }

        // Curva-S semanal: do início do plano até o maior entre fim do plano e hoje.
        $starts = $items->pluck('planned_start_at')->filter()->map(fn ($d) => Carbon::parse($d));
        $ends   = $items->pluck('planned_end_at')->filter()->map(fn ($d) => Carbon::parse($d));
        $curveStart = ($starts->min() ?: Carbon::parse($project->start_date ?: $baseline->frozen_at))->copy()->startOfWeek();
        $curveEnd   = ($ends->max() && $ends->max()->gt($asOf) ? $ends->max() : $asOf)->copy()->endOfWeek();

        $series = [];
        $cursor = $curveStart->copy(); $guard = 0;
        while ($cursor->lte($curveEnd) && $guard++ < 260) {
            $w = $cursor->copy()->endOfWeek();
            $future = $w->gt($asOf);
            $pt = [
                'date' => $cursor->toDateString(),
                'pv'   => $pvGen($w, $hoursVal),                     // PV projeta até o fim do plano
                'ev'   => $future ? null : $evGen($w, $hoursVal),    // EV/AC só até hoje
                'ac'   => $future ? null : $acAt($w),
            ];
            if ($hasCost) {
                $pt['pv_cost'] = $pvGen($w, $costValF);
                $pt['ev_cost'] = $future ? null : $evGen($w, $costValF);
                $pt['ac_cost'] = $future ? null : $acCostAt($w);
            }
            $series[] = $pt;
            $cursor->addWeek();
        }

        return response()->json([
            'has_baseline' => true,
            'has_cost'     => $hasCost,
            'baseline'     => $this->baselineMeta($baseline),
            'as_of'        => $asOf->toDateString(),
            'metrics'      => [
                'bac' => round($bac, 2),
                'pv'  => $pv, 'ev' => $ev, 'ac' => $ac,
                'sv'  => round($ev - $pv, 2), 'cv' => round($ev - $ac, 2),
                'spi' => $spi, 'cpi' => $cpi,
                'eac' => $eac, 'etc' => $eac !== null ? round($eac - $ac, 2) : null,
                'vac' => $eac !== null ? round($bac - $eac, 2) : null,
                'pct_planned' => $bac > 0 ? round($pv / $bac * 100, 1) : null,
                'pct_real'    => $bac > 0 ? round($ev / $bac * 100, 1) : null,
            ],
            'cost'  => $cost,
            'curve' => $series,
        ]);
    }

    /** Indicadores OPERACIONAIS (independem de baseline): produtividade por consultor + lead/cycle time + atrasadas. */
    public function operational(Project $project): JsonResponse
    {
        $stageIds = $project->stages()->pluck('id')->all();

        $deliveries = DB::table('stage_deliveries')
            ->whereIn('stage_id', $stageIds ?: [0])->whereNull('deleted_at')
            ->select('id', 'title', 'responsible_user_id', 'hours_planned', 'status', 'due_date', 'created_at', 'actual_start_at', 'completed_at')
            ->get();

        $total  = $deliveries->count();
        $done   = $deliveries->filter(fn ($d) => $d->status === 'done' && $d->completed_at);
        $today  = now()->startOfDay();
        $overdue = $deliveries->filter(fn ($d) => $d->status !== 'done' && $d->due_date && Carbon::parse($d->due_date)->lt($today))->count();

        // Lead (criação→conclusão) e Cycle (início→conclusão) por atividade concluída, em dias.
        $flowItems = $done->map(function ($d) {
            $completed = Carbon::parse($d->completed_at);
            $lead  = abs(Carbon::parse($d->created_at)->diffInDays($completed));
            $cycle = $d->actual_start_at ? abs(Carbon::parse($d->actual_start_at)->diffInDays($completed)) : null;
            return ['title' => $d->title, 'completed_at' => $completed->toDateString(), 'lead_days' => $lead, 'cycle_days' => $cycle];
        })->sortByDesc('completed_at')->values();

        $cycleVals = $flowItems->pluck('cycle_days')->filter(fn ($v) => $v !== null);
        $flow = [
            'count'          => $flowItems->count(),
            'lead_avg_days'  => $flowItems->count() ? round($flowItems->avg('lead_days'), 1) : null,
            'cycle_avg_days' => $cycleVals->count() ? round($cycleVals->avg(), 1) : null,
            'items'          => $flowItems->take(30)->all(),
        ];

        // Horas apontadas por consultor (approved + released) no projeto.
        $hoursByUser = DB::table('timesheets')
            ->where('project_id', $project->id)->whereNull('deleted_at')
            ->whereIn('status', [Timesheet::STATUS_APPROVED, Timesheet::STATUS_RELEASED])
            ->groupBy('user_id')->selectRaw('user_id, COALESCE(SUM(effort_minutes),0)/60.0 AS h')
            ->pluck('h', 'user_id');

        // Produtividade por consultor: atividades concluídas + horas planejadas dessas + horas apontadas + eficiência.
        $doneByUser = $done->groupBy('responsible_user_id');
        $userIds = collect($doneByUser->keys())->merge($hoursByUser->keys())->filter()->unique()->values();
        $names   = DB::table('users')->whereIn('id', $userIds->all() ?: [0])->pluck('name', 'id');

        $productivity = $userIds->map(function ($uid) use ($doneByUser, $hoursByUser, $names) {
            $dset        = $doneByUser->get($uid, collect());
            $plannedDone = round((float) $dset->sum('hours_planned'), 2);
            $actual      = round((float) ($hoursByUser[$uid] ?? 0), 2);
            return [
                'user_id'      => (int) $uid,
                'name'         => $names[$uid] ?? 'Consultor',
                'done_count'   => $dset->count(),
                'hours_done'   => $plannedDone,
                'hours_actual' => $actual,
                'efficiency'   => $actual > 0 ? round($plannedDone / $actual, 2) : null,
            ];
        })->sortByDesc('done_count')->values();

        return response()->json([
            'totals'       => [
                'deliveries'  => $total,
                'done'        => $done->count(),
                'overdue'     => $overdue,
                'overdue_pct' => $total > 0 ? round($overdue / $total * 100, 1) : 0,
            ],
            'productivity' => $productivity->all(),
            'flow'         => $flow,
        ]);
    }

    /**
     * PORTFÓLIO: resumo de indicadores (EVM em horas + operacional) de TODOS os projetos (filtráveis),
     * numa tela só. Cálculo em LOTE (poucas queries) — não roda o /evm por projeto.
     */
    public function portfolio(Request $request): JsonResponse
    {
        $today   = now()->endOfDay();
        $todayStart = now()->startOfDay();

        $q = $this->portfolioBaseQuery($request)->with(['customer:id,name', 'coordinators:id,name']);
        $projects = $q->orderBy('name')->get(['id', 'name', 'code', 'status', 'customer_id']);
        $ids = $projects->pluck('id')->all();
        if (empty($ids)) return response()->json(['projects' => []]);

        // Baselines current + itens (2 queries).
        $baselines = ProjectBaseline::whereIn('project_id', $ids)->where('is_current', true)->get()->keyBy('project_id');
        $blIds = $baselines->pluck('id')->all();
        $itemsByBl = $blIds
            ? StageBaselineItem::whereIn('project_baseline_id', $blIds)->get()->groupBy('project_baseline_id')
            : collect();

        // Atividades por projeto (via etapas) — done/total/overdue + EV.
        $delivRows = DB::table('stage_deliveries as d')
            ->join('project_stages as s', 's.id', '=', 'd.stage_id')
            ->whereIn('s.project_id', $ids)->whereNull('d.deleted_at')->whereNull('s.deleted_at')
            ->select('s.project_id', 'd.id', 'd.status', 'd.due_date', 'd.completed_at')->get();
        $doneAt   = $delivRows->where('status', 'done')->whereNotNull('completed_at')->pluck('completed_at', 'id');
        $stageEndAt = DB::table('project_stages')->whereIn('project_id', $ids)
            ->where('status', 'done')->whereNotNull('actual_end_at')->pluck('actual_end_at', 'id');
        $byProj = $delivRows->groupBy('project_id');

        // AC (horas apontadas approved+released) por projeto.
        $acByProj = DB::table('timesheets')->whereIn('project_id', $ids)->whereNull('deleted_at')
            ->whereIn('status', [Timesheet::STATUS_APPROVED, Timesheet::STATUS_RELEASED])
            ->groupBy('project_id')->selectRaw('project_id, COALESCE(SUM(effort_minutes),0)/60.0 AS h')->pluck('h', 'project_id');

        $rows = $projects->map(function ($p) use ($baselines, $itemsByBl, $doneAt, $stageEndAt, $byProj, $acByProj, $today, $todayStart) {
            $delivs  = $byProj->get($p->id, collect());
            $total   = $delivs->count();
            $done    = $delivs->where('status', 'done')->whereNotNull('completed_at')->count();
            $overdue = $delivs->filter(fn ($d) => $d->status !== 'done' && $d->due_date && Carbon::parse($d->due_date)->lt($todayStart))->count();
            $ac      = round((float) ($acByProj[$p->id] ?? 0), 2);

            $bl = $baselines->get($p->id);
            $pv = 0.0; $ev = 0.0; $bac = 0.0; $spi = null; $cpi = null; $pctP = null; $pctR = null;
            if ($bl) {
                $bac = (float) $bl->planned_hours_total;
                foreach ($itemsByBl->get($bl->id, collect()) as $it) {
                    $h = (float) $it->planned_hours;
                    if ($h <= 0) continue;
                    if ($e = $it->planned_end_at) {                       // PV distribuído no tempo
                        $end = Carbon::parse($e)->endOfDay();
                        if (! $it->planned_start_at) { $pv += $today->gte($end) ? $h : 0.0; }
                        else {
                            $st = Carbon::parse($it->planned_start_at)->startOfDay();
                            if ($today->gt($st)) {
                                if ($today->gte($end)) $pv += $h;
                                else { $span = max(1, $st->diffInDays($end)); $pv += $h * min(1.0, max(0.0, $st->diffInDays($today) / $span)); }
                            }
                        }
                    }
                    $dd = null;                                          // EV = concluídas
                    if ($it->stage_delivery_id && isset($doneAt[$it->stage_delivery_id])) $dd = Carbon::parse($doneAt[$it->stage_delivery_id]);
                    elseif (! $it->stage_delivery_id && $it->stage_id && isset($stageEndAt[$it->stage_id])) $dd = Carbon::parse($stageEndAt[$it->stage_id]);
                    if ($dd && $dd->lte($today)) $ev += $h;
                }
                $pv = round($pv, 2); $ev = round($ev, 2);
                $spi  = $pv > 0 ? round($ev / $pv, 3) : null;
                $cpi  = $ac > 0 ? round($ev / $ac, 3) : null;
                $pctP = $bac > 0 ? round($pv / $bac * 100, 1) : null;
                $pctR = $bac > 0 ? round($ev / $bac * 100, 1) : null;
            }
            $overduePct = $total > 0 ? round($overdue / $total * 100, 1) : 0;
            $health = 'ok';
            if (($spi !== null && $spi < 0.9) || $overduePct >= 20) $health = 'late';
            elseif (($spi !== null && $spi < 1) || $overduePct > 0)  $health = 'risk';

            return [
                'id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'status' => $p->status,
                'customer'     => $p->customer?->name,
                'coordinators' => $p->coordinators->pluck('name')->all(),
                'has_baseline' => (bool) $bl,
                'pct_planned'  => $pctP, 'pct_real' => $pctR, 'spi' => $spi, 'cpi' => $cpi,
                'hours_planned' => round($bac, 2), 'hours_ev' => $ev, 'hours_actual' => $ac,
                'deliveries' => $total, 'done' => $done, 'overdue' => $overdue, 'overdue_pct' => $overduePct,
                'health' => $health,
            ];
        })->values();

        return response()->json(['projects' => $rows]);
    }

    /** Query de projetos do portfólio com os filtros aplicados (compartilhada entre portfolio e a curva). */
    private function portfolioBaseQuery(Request $request)
    {
        $q = Project::query();
        if ($s = trim((string) $request->get('search'))) {
            $q->where(fn ($x) => $x->where('name', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%"));
        }
        if ($cid = $request->get('customer_id')) $q->where('customer_id', $cid);
        if ($coordId = $request->get('coordinator_id')) $q->whereHas('coordinators', fn ($c) => $c->where('users.id', $coordId));
        $status = $request->get('status');
        if ($status === 'active') $q->active();
        elseif ($status === 'open') $q->open();
        elseif ($status) $q->where('status', $status);
        else $q->open();
        return $q;
    }

    /**
     * CURVA-S CONSOLIDADA da carteira: PV/EV/AC (horas) somados por semana entre TODOS os projetos
     * filtrados que têm baseline. PV/EV/AC são aditivos → basta juntar os itens e os apontamentos.
     */
    public function portfolioCurve(Request $request): JsonResponse
    {
        $asOf = now()->endOfDay();
        $ids  = $this->portfolioBaseQuery($request)->pluck('id')->all();
        if (empty($ids)) return response()->json(['curve' => [], 'as_of' => $asOf->toDateString(), 'projects_with_baseline' => 0]);

        $blIds = ProjectBaseline::whereIn('project_id', $ids)->where('is_current', true)->pluck('id')->all();
        $items = $blIds ? StageBaselineItem::whereIn('project_baseline_id', $blIds)->get() : collect();
        if ($items->isEmpty()) return response()->json(['curve' => [], 'as_of' => $asOf->toDateString(), 'projects_with_baseline' => 0]);

        $doneAt = DB::table('stage_deliveries')
            ->whereIn('id', $items->pluck('stage_delivery_id')->filter()->all() ?: [0])
            ->where('status', 'done')->whereNotNull('completed_at')->pluck('completed_at', 'id');
        $stageEndAt = DB::table('project_stages')
            ->whereIn('id', $items->whereNull('stage_delivery_id')->pluck('stage_id')->filter()->all() ?: [0])
            ->where('status', 'done')->whereNotNull('actual_end_at')->pluck('actual_end_at', 'id');

        $acRows = DB::table('timesheets')->whereIn('project_id', $ids)->whereNull('deleted_at')
            ->whereIn('status', [Timesheet::STATUS_APPROVED, Timesheet::STATUS_RELEASED])
            ->groupBy('date')->selectRaw('date, COALESCE(SUM(effort_minutes),0)/60.0 AS h')
            ->get()->map(fn ($r) => ['date' => Carbon::parse($r->date)->endOfDay(), 'h' => (float) $r->h]);

        $itemDoneAt = function (StageBaselineItem $it) use ($doneAt, $stageEndAt): ?Carbon {
            if ($it->stage_delivery_id && isset($doneAt[$it->stage_delivery_id])) return Carbon::parse($doneAt[$it->stage_delivery_id]);
            if (! $it->stage_delivery_id && $it->stage_id && isset($stageEndAt[$it->stage_id])) return Carbon::parse($stageEndAt[$it->stage_id]);
            return null;
        };
        $pvAt = function (Carbon $date) use ($items): float {
            $sum = 0.0;
            foreach ($items as $it) {
                $h = (float) $it->planned_hours; if ($h <= 0) continue;
                if (! $it->planned_end_at) continue;
                $end = Carbon::parse($it->planned_end_at)->endOfDay();
                if (! $it->planned_start_at) { $sum += $date->gte($end) ? $h : 0.0; continue; }
                $st = Carbon::parse($it->planned_start_at)->startOfDay();
                if ($date->lte($st)) continue;
                if ($date->gte($end)) { $sum += $h; continue; }
                $span = max(1, $st->diffInDays($end)); $sum += $h * min(1.0, max(0.0, $st->diffInDays($date) / $span));
            }
            return round($sum, 2);
        };
        $evAt = function (Carbon $date) use ($items, $itemDoneAt): float {
            $sum = 0.0;
            foreach ($items as $it) { $d = $itemDoneAt($it); if ($d && $d->lte($date)) $sum += (float) $it->planned_hours; }
            return round($sum, 2);
        };
        $acAt = fn (Carbon $date) => round($acRows->filter(fn ($r) => $r['date']->lte($date))->sum('h'), 2);

        $starts = $items->pluck('planned_start_at')->filter()->map(fn ($d) => Carbon::parse($d));
        $ends   = $items->pluck('planned_end_at')->filter()->map(fn ($d) => Carbon::parse($d));
        $curveStart = ($starts->min() ?: $asOf)->copy()->startOfWeek();
        $curveEnd   = ($ends->max() && $ends->max()->gt($asOf) ? $ends->max() : $asOf)->copy()->endOfWeek();

        $series = []; $cursor = $curveStart->copy(); $guard = 0;
        while ($cursor->lte($curveEnd) && $guard++ < 400) {
            $w = $cursor->copy()->endOfWeek(); $future = $w->gt($asOf);
            $series[] = ['date' => $cursor->toDateString(), 'pv' => $pvAt($w), 'ev' => $future ? null : $evAt($w), 'ac' => $future ? null : $acAt($w)];
            $cursor->addWeek();
        }

        return response()->json(['curve' => $series, 'as_of' => $asOf->toDateString(), 'projects_with_baseline' => count($blIds)]);
    }

    private function baselineMeta(ProjectBaseline $b): array
    {
        return [
            'id'                  => $b->id,
            'label'               => $b->label,
            'frozen_at'           => optional($b->frozen_at)->toIso8601String(),
            'frozen_by'           => $b->frozenBy?->name,
            'planned_hours_total' => (float) $b->planned_hours_total,
            'planned_cost_total'  => (float) $b->planned_cost_total,
            'notes'               => $b->notes,
        ];
    }
}
