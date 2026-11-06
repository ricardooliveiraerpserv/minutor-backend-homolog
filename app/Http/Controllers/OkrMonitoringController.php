<?php

namespace App\Http\Controllers;

use App\Models\OkrKeyResult;
use App\Services\OkrProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acompanhamento Mensal: lista os KRs de um ciclo (com filtros) já com a série
 * mensal calculada (meta, realizado, acumulados, atingimento, aderência, semáforo).
 * Somente leitura (okr.view). A edição do realizado é em OkrKeyResultController@saveResult.
 */
class OkrMonitoringController extends Controller
{
    public function __construct(private OkrProgressService $progress) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'cycle_id' => 'required|integer|exists:okr_cycles,id',
            'area_id' => 'nullable|integer',
            'objective_id' => 'nullable|integer',
            'responsavel_id' => 'nullable|integer',
        ]);

        $krs = OkrKeyResult::query()
            ->with(['monthlyTargets', 'monthlyResults', 'objective.cycle', 'objective.area:id,name', 'responsavel:id,name'])
            ->whereHas('objective', function ($q) use ($v) {
                $q->where('okr_cycle_id', $v['cycle_id'])
                    ->when(!empty($v['area_id']), fn ($qq) => $qq->where('okr_area_id', $v['area_id']))
                    ->when(!empty($v['objective_id']), fn ($qq) => $qq->where('id', $v['objective_id']));
            })
            ->when(!empty($v['responsavel_id']), fn ($q) => $q->where('responsavel_id', $v['responsavel_id']))
            ->orderBy('okr_objective_id')
            ->orderBy('id')
            ->get();

        $rows = $krs->map(function (OkrKeyResult $kr) {
            $series = $this->progress->seriesForKeyResult($kr);
            return [
                'id' => $kr->id,
                'name' => $kr->name,
                'unit' => $kr->unit,
                'unit_label' => $kr->unit_label,
                'direction' => $kr->direction,
                'aggregation' => $kr->aggregation,
                'target_value' => $kr->target_value,
                'objective_id' => $kr->okr_objective_id,
                'objective_title' => $kr->objective?->title,
                'area_name' => $kr->objective?->area?->name,
                'responsavel_name' => $kr->responsavel?->name,
                'months' => $series['months'],
                'summary' => $series['summary'],
            ];
        });

        return response()->json(['data' => $rows]);
    }
}
