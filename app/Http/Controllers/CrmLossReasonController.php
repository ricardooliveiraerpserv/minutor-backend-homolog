<?php

namespace App\Http\Controllers;

use App\Models\CrmLossReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRM — cadastro configurável de Motivos de Perda (Cadastros CRM › Motivos de Perda). */
class CrmLossReasonController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => CrmLossReason::orderBy('ordem')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate(['name' => 'required|string|max:80|unique:crm_loss_reasons,name', 'ordem' => 'nullable|integer']);
        return response()->json(['data' => CrmLossReason::create($v + ['active' => true])], 201);
    }

    public function update(Request $request, CrmLossReason $lossReason): JsonResponse
    {
        $v = $request->validate([
            'name'   => 'sometimes|string|max:80|unique:crm_loss_reasons,name,' . $lossReason->id,
            'ordem'  => 'nullable|integer',
            'active' => 'boolean',
        ]);
        $lossReason->update($v);
        return response()->json(['data' => $lossReason]);
    }
}
