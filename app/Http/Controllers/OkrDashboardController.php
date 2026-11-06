<?php

namespace App\Http\Controllers;

use App\Models\OkrCycle;
use App\Services\OkrConsolidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard executivo de OKR: consolidação por área/corporativo, KRs em risco e
 * comparativo entre anos. Somente leitura (okr.view).
 */
class OkrDashboardController extends Controller
{
    public function __construct(private OkrConsolidationService $consolidation) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['cycle_id' => 'required|integer|exists:okr_cycles,id']);

        $eager = ['objectives.keyResults.monthlyTargets', 'objectives.keyResults.monthlyResults', 'objectives.area:id,name', 'objectives.children.keyResults.monthlyTargets', 'objectives.children.keyResults.monthlyResults'];
        $cycle = OkrCycle::with($eager)->findOrFail($v['cycle_id']);

        $overview = $this->consolidation->cycleOverview($cycle);

        // Comparativo entre anos: todos os ciclos da mesma empresa.
        $years = OkrCycle::with(['objectives.keyResults.monthlyTargets', 'objectives.keyResults.monthlyResults'])
            ->where('company_id', $cycle->company_id)
            ->orderBy('year')
            ->get()
            ->map(fn ($c) => ['cycle_id' => $c->id, 'year' => $c->year, 'overall_achievement' => $this->consolidation->overallAchievement($c)])
            ->values();

        return response()->json(['data' => array_merge($overview, ['years' => $years])]);
    }
}
