<?php

namespace App\Http\Controllers;

use App\Models\CrmSegment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRM — cadastro configurável de Segmentos de mercado (Cadastros CRM › Segmentos). */
class CrmSegmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = CrmSegment::query()->orderBy('ordem')->orderBy('name');
        if ($request->boolean('only_active')) $q->where('active', true);
        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name'  => 'required|string|max:80|unique:crm_segments,name',
            'ordem' => 'nullable|integer',
        ]);
        return response()->json(['data' => CrmSegment::create($v + ['active' => true])], 201);
    }

    public function update(Request $request, CrmSegment $segment): JsonResponse
    {
        $v = $request->validate([
            'name'   => 'sometimes|string|max:80|unique:crm_segments,name,' . $segment->id,
            'ordem'  => 'nullable|integer',
            'active' => 'boolean',
        ]);
        $segment->update($v);
        return response()->json(['data' => $segment]);
    }

    public function destroy(CrmSegment $segment): JsonResponse
    {
        $segment->delete();
        return response()->json(['data' => true]);
    }
}
