<?php

namespace App\Http\Controllers;

use App\Models\ContractRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user     = auth()->user();
        $query    = ContractRequest::with(['customer:id,name', 'createdBy:id,name', 'reviewedBy:id,name', 'contract:id,project_name'])
            ->when($request->query('status'), fn($q) => $q->where('status', $request->query('status')))
            ->when($request->query('urgencia'), fn($q) => $q->where('nivel_urgencia', $request->query('urgencia')))
            ->when($request->query('search'), function ($q) use ($request) {
                $s = '%' . $request->query('search') . '%';
                $q->where(fn($qb) => $qb->where('area_requisitante', 'ilike', $s)
                    ->orWhere('descricao', 'ilike', $s)
                    ->orWhereHas('customer', fn($c) => $c->where('name', 'ilike', $s)));
            });

        // Clientes só veem as próprias requisições
        if ($user?->isCliente() && $user->customer_id) {
            $query->where('customer_id', $user->customer_id);
        }

        $query->orderByRaw("CASE nivel_urgencia
            WHEN 'altissimo' THEN 0
            WHEN 'alto'      THEN 1
            WHEN 'medio'     THEN 2
            WHEN 'baixo'     THEN 3
            ELSE 4 END")
            ->orderBy('created_at', 'desc');

        return response()->json($query->paginate($request->query('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'area_requisitante'      => 'required|string|max:255',
            'product_owner'          => 'nullable|string|max:255',
            'modulo_tecnologia'      => 'nullable|string|max:255',
            'tipo_necessidade'       => 'required|string|in:' . implode(',', array_keys(ContractRequest::TIPOS)),
            'tipo_necessidade_outro' => 'nullable|string|max:255',
            'nivel_urgencia'         => 'required|string|in:' . implode(',', array_keys(ContractRequest::URGENCIAS)),
            'descricao'              => 'nullable|string',
            'cenario_atual'          => 'nullable|string',
            'cenario_desejado'       => 'nullable|string',
        ]);

        // Resolve customer_id: cliente usa o próprio, admin pode passar
        $customerId = $user?->isCliente()
            ? $user->customer_id
            : ($request->input('customer_id') ?? $user->customer_id);

        if (!$customerId) {
            return response()->json(['message' => 'Cliente não identificado.'], 422);
        }

        $req = ContractRequest::create(array_merge($validated, [
            'customer_id'    => $customerId,
            'created_by_id'  => $user->id,
            'status'         => ContractRequest::STATUS_PENDENTE,
        ]));

        return response()->json($req->load(['customer:id,name', 'createdBy:id,name']), 201);
    }

    public function show(ContractRequest $contractRequest): JsonResponse
    {
        $user = auth()->user();
        if ($user?->isCliente() && $user->customer_id !== $contractRequest->customer_id) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }

        return response()->json($contractRequest->load(['customer:id,name', 'createdBy:id,name', 'reviewedBy:id,name', 'contract:id,project_name']));
    }

    public function review(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        $validated = $request->validate([
            'status'          => 'required|in:em_analise,aprovado,recusado',
            'notas_revisao'   => 'nullable|string',
        ]);

        $contractRequest->update(array_merge($validated, [
            'reviewed_by_id' => auth()->id(),
            'reviewed_at'    => now(),
        ]));

        return response()->json($contractRequest->fresh(['customer:id,name', 'reviewedBy:id,name']));
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'tipos'     => ContractRequest::TIPOS,
            'urgencias' => ContractRequest::URGENCIAS,
        ]);
    }
}
