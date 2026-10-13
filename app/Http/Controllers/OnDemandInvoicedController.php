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
        if ($data['invoiced'] && $data['year_month'] >= now()->format('Y-m')) {
            $liberado = \App\Models\OnDemandBillingRelease::where('project_id', $data['project_id'])
                ->where('year_month', $data['year_month'])->exists();
            if (!$liberado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Faturamento On Demand não liberado pelo coordenador responsável para esta competência. Libere em "Faturamento On Demand" antes de marcar como faturado.',
                ], 422);
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
