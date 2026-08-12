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
        $label = trim((string) $request->input('label', '')) ?: 'Linha de base '.now()->format('d/m/Y');
        $notes = $request->input('notes');
        $comp  = now()->format('Y-m');                 // competência do congelamento (custo/hora vigente)
        $costSvc = app(ConsultorCostService::class);

        $stages = $project->stages()->with('deliveries')->get();

        // Custo/hora dos responsáveis, congelado na competência atual (mesma regra da Rentabilidade).
        $userIds = $stages->pluck('responsible_user_id')
            ->merge($stages->flatMap(fn ($s) => $s->deliveries->pluck('responsible_user_id')))
            ->filter()->unique()->values();
        $users = User::with('partner')->whereIn('id', $userIds->all() ?: [0])->get()->keyBy('id');
        $rateFor = fn ($uid) => ($uid && $users->has($uid)) ? $costSvc->hourlyCost($users->get($uid), $comp) : 0.0;

        $baseline = DB::transaction(function () use ($project, $label, $notes, $stages, $rateFor) {
            // Só uma baseline "current" por projeto.
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
                    // Etapa sem atividades: congela a etapa como item (PV/EV no nível de etapa).
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

        return response()->json([
            'message'  => 'Linha de base congelada.',
            'baseline' => $this->baselineMeta($baseline->fresh()),
        ], 201);
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
