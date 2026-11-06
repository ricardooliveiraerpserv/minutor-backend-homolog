<?php

namespace App\Http\Controllers;

use App\Models\OkrCycle;
use App\Models\OkrPeriodClosure;
use App\Services\OkrAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fontes automáticas (Fase 4): catálogo e sincronização. A sincronização só
 * preenche KRs marcados como data_source_type=automatico e nunca sobrescreve
 * meses fechados.
 */
class OkrAutomationController extends Controller
{
    public function __construct(private OkrAutomationService $automation) {}

    /** Catálogo de fontes disponíveis (para o cadastro do KR). */
    public function sources(): JsonResponse
    {
        return response()->json(['data' => $this->automation->catalog()]);
    }

    /** Sincroniza os KRs automáticos de um ciclo (opcionalmente só uma competência). */
    public function sync(Request $request): JsonResponse
    {
        $v = $request->validate([
            'cycle_id' => 'required|integer|exists:okr_cycles,id',
            'year_month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $cycle = OkrCycle::findOrFail($v['cycle_id']);

        $closed = OkrPeriodClosure::where('okr_cycle_id', $cycle->id)
            ->where('status', 'fechado')
            ->get(['okr_area_id', 'year_month']);

        $isClosed = function (int $cycleId, ?int $areaId, string $ym) use ($closed): bool {
            return $closed->contains(function ($c) use ($areaId, $ym) {
                return $c->year_month === $ym && ($c->okr_area_id === null || $c->okr_area_id === $areaId);
            });
        };

        $report = $this->automation->syncCycle($cycle, $v['year_month'] ?? null, $request->user()->id, $isClosed);

        return response()->json(['data' => $report]);
    }
}
