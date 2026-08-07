<?php

namespace App\Http\Controllers;

use App\Models\CrmDiscardReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRM — cadastro de Motivos de Descarte do funil de prospecção.
 * `dias_repescagem` (opcional) agenda a repescagem automática do lead descartado.
 */
class CrmDiscardReasonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = CrmDiscardReason::query();
        if ($request->boolean('active')) {
            $q->where('active', true);
        }
        return response()->json(['data' => $q->orderBy('ordem')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name'            => 'required|string|max:80',
            'ordem'           => 'nullable|integer',
            'dias_repescagem' => 'nullable|integer|min:1|max:3650',
        ]);
        return response()->json(['data' => CrmDiscardReason::create($v + ['active' => true])], 201);
    }

    public function update(Request $request, CrmDiscardReason $discardReason): JsonResponse
    {
        $v = $request->validate([
            'name'            => 'sometimes|string|max:80',
            'ordem'           => 'nullable|integer',
            'dias_repescagem' => 'nullable|integer|min:1|max:3650',
            'active'          => 'boolean',
        ]);
        // dias_repescagem null explícito = "nunca repesca" — preserva a limpeza do campo.
        if ($request->exists('dias_repescagem')) {
            $v['dias_repescagem'] = $request->input('dias_repescagem') !== null && $request->input('dias_repescagem') !== ''
                ? (int) $request->input('dias_repescagem') : null;
        }
        $discardReason->update($v);
        return response()->json(['data' => $discardReason]);
    }

    public function destroy(CrmDiscardReason $discardReason): JsonResponse
    {
        $discardReason->delete();
        return response()->json(null, 204);
    }
}
