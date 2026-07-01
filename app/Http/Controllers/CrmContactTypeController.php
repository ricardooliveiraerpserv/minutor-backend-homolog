<?php

namespace App\Http\Controllers;

use App\Models\CrmContactType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** CRM — cadastro de Tipos de Contato (follow-up). */
class CrmContactTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = CrmContactType::query()->orderBy('ordem')->orderBy('nome');
        if (!$request->boolean('all')) $q->where('ativo', true);
        return response()->json(['data' => $q->get()]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(in_array($request->user()->type, ['admin', 'administrativo'], true), 403);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $v = $request->validate([
            'nome'  => 'required|string|max:80',
            'ordem' => 'nullable|integer|min:0',
            'ativo' => 'nullable|boolean',
        ]);
        $slug = Str::slug($v['nome'], '_') ?: Str::random(8);
        $base = $slug; $i = 1;
        while (CrmContactType::where('slug', $slug)->exists()) $slug = $base . '_' . (++$i);
        $row = CrmContactType::create([
            'nome' => $v['nome'], 'slug' => $slug,
            'ordem' => $v['ordem'] ?? (int) CrmContactType::max('ordem') + 1,
            'ativo' => $v['ativo'] ?? true,
        ]);
        return response()->json(['data' => $row], 201);
    }

    public function update(Request $request, CrmContactType $crmContactType): JsonResponse
    {
        $this->authorizeManage($request);
        $v = $request->validate([
            'nome'  => 'sometimes|string|max:80',
            'ordem' => 'nullable|integer|min:0',
            'ativo' => 'nullable|boolean',
        ]);
        $crmContactType->update($v);
        return response()->json(['data' => $crmContactType->fresh()]);
    }

    public function destroy(Request $request, CrmContactType $crmContactType): JsonResponse
    {
        $this->authorizeManage($request);
        // não apaga (preserva histórico de follow-ups); apenas inativa.
        $crmContactType->update(['ativo' => false]);
        return response()->json(null, 204);
    }
}
