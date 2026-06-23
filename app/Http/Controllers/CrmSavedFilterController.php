<?php

namespace App\Http\Controllers;

use App\Models\CrmSavedFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRM — Filtros salvos (por usuário e por tela). */
class CrmSavedFilterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['scope' => 'required|string|max:40']);
        $data = CrmSavedFilter::where('user_id', $request->user()->id)
            ->where('scope', $request->scope)
            ->orderBy('name')->get(['id', 'name', 'payload']);
        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'scope'   => 'required|string|max:40',
            'name'    => 'required|string|max:120',
            'payload' => 'required|array',
        ]);
        $v['user_id'] = $request->user()->id;
        // Sobrescreve se já existir um com o mesmo nome (mesma tela/usuário).
        $f = CrmSavedFilter::updateOrCreate(
            ['user_id' => $v['user_id'], 'scope' => $v['scope'], 'name' => $v['name']],
            ['payload' => $v['payload']]
        );
        return response()->json(['data' => $f->only('id', 'name', 'payload')], 201);
    }

    public function destroy(Request $request, CrmSavedFilter $crmSavedFilter): JsonResponse
    {
        abort_unless($crmSavedFilter->user_id === $request->user()->id, 403);
        $crmSavedFilter->delete();
        return response()->json(null, 204);
    }
}
