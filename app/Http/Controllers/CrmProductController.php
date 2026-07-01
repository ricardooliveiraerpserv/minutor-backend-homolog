<?php

namespace App\Http\Controllers;

use App\Models\CrmProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRM — catálogo de Produtos e Serviços (CRUD). */
class CrmProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = CrmProduct::query()->with('contractType:id,name,code', 'serviceType:id,name,code');
        if ($request->boolean('only_active')) $q->where('ativo', true);
        if ($request->filled('categoria'))    $q->where('categoria', $request->categoria);
        if ($request->filled('search'))       $q->where('name', 'ilike', '%' . $request->search . '%');
        return response()->json(['data' => $q->orderBy('name')->get()]);
    }

    private function rules(): array
    {
        return [
            'name'               => 'required|string|max:160',
            'categoria'          => 'required|string|in:' . implode(',', CrmProduct::CATEGORIAS),
            'tipo_precificacao'  => 'required|string|in:' . implode(',', CrmProduct::PRECIFICACOES),
            'valor'              => 'nullable|numeric|min:0',
            'descricao_tecnica'  => 'nullable|string',
            'ativo'              => 'boolean',
            'contract_type_id'   => 'nullable|exists:contract_types,id',
            'service_type_id'    => 'nullable|exists:service_types,id',
            'tipo_faturamento'   => 'nullable|in:on_demand,banco_horas_mensal,banco_horas_fixo,por_servico,saas',
            'categoria_contrato' => 'nullable|in:projeto,sustentacao',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $p = CrmProduct::create($request->validate($this->rules()));
        return response()->json(['data' => $p], 201);
    }

    public function update(Request $request, CrmProduct $crmProduct): JsonResponse
    {
        $crmProduct->update($request->validate($this->rules()));
        return response()->json(['data' => $crmProduct->fresh()]);
    }

    public function destroy(CrmProduct $crmProduct): JsonResponse
    {
        $crmProduct->delete();
        return response()->json(null, 204);
    }
}
