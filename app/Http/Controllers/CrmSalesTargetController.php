<?php

namespace App\Http\Controllers;

use App\Models\CrmSalesTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Metas comerciais (quota) por competência — empresa e por vendedor. */
class CrmSalesTargetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $periodo = $request->query('periodo', now()->format('Y-m'));
        $rows = CrmSalesTarget::where('periodo', $periodo)->get();
        return response()->json(['data' => [
            'periodo'      => $periodo,
            'empresa'      => (float) ($rows->firstWhere('user_id', null)?->valor_meta ?? 0),
            'por_vendedor' => $rows->whereNotNull('user_id')->mapWithKeys(fn ($t) => [$t->user_id => (float) $t->valor_meta]),
        ]]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $v = $request->validate([
            'periodo'    => 'required|string|max:7',
            'user_id'    => 'nullable|exists:users,id',
            'valor_meta' => 'required|numeric|min:0',
        ]);
        $target = CrmSalesTarget::updateOrCreate(
            ['periodo' => $v['periodo'], 'user_id' => $v['user_id'] ?? null],
            ['valor_meta' => $v['valor_meta'], 'created_by_id' => auth()->id()],
        );
        return response()->json(['data' => $target]);
    }
}
