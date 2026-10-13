<?php

namespace App\Http\Controllers;

use App\Models\OnDemandInvoicedMonth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liga/desliga o flag "faturado / NFS-e enviada" de um mês de um projeto
 * On Demand (pai). Só admin/administrativo. A existência da linha = faturado.
 */
class OnDemandInvoicedController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isAdministrativo()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'year_month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'invoiced'   => 'required|boolean',
        ]);

        // Backstop: não deixa MARCAR faturado se o coordenador não liberou o faturamento
        // On Demand na competência (regra vale deste mês pra frente; meses anteriores ok).
        // Só se aplica quando HÁ horas no mês (sem movimento = nada a liberar/faturar).
        if ($data['invoiced'] && $data['year_month'] >= now()->format('Y-m')) {
            $start = $data['year_month'] . '-01';
            $end   = \Carbon\Carbon::parse($start)->endOfMonth()->toDateString();
            $ids = collect([$data['project_id']])
                ->merge(\App\Models\Project::where('parent_project_id', $data['project_id'])->pluck('id'))
                ->all();
            $min = (float) \App\Models\Timesheet::whereIn('project_id', $ids)
                ->whereBetween('date', [$start, $end])
                ->whereNotIn('status', [
                    \App\Models\Timesheet::STATUS_REJECTED, \App\Models\Timesheet::STATUS_CONFLICTED,
                    \App\Models\Timesheet::STATUS_INTERNAL, \App\Models\Timesheet::STATUS_LATE,
                ])
                ->sum('effort_minutes');
            if ($min > 0) {
                $liberado = \App\Models\OnDemandBillingRelease::where('project_id', $data['project_id'])
                    ->where('year_month', $data['year_month'])->exists();
                if (!$liberado) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Faturamento On Demand não liberado pelo coordenador responsável para esta competência. Libere em "Faturamento On Demand" antes de marcar como faturado.',
                    ], 422);
                }
            }
        }

        if ($data['invoiced']) {
            OnDemandInvoicedMonth::firstOrCreate(
                ['project_id' => $data['project_id'], 'year_month' => $data['year_month']],
                ['invoiced_at' => now(), 'invoiced_by' => $user->id],
            );
        } else {
            OnDemandInvoicedMonth::where('project_id', $data['project_id'])
                ->where('year_month', $data['year_month'])
                ->delete();
        }

        return response()->json(['success' => true, 'invoiced' => (bool) $data['invoiced']]);
    }
}
