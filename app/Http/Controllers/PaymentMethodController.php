<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Http\Traits\ResponseHelpers;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Schema(
 *     schema="PaymentMethod",
 *     type="object",
 *     title="Payment Method",
 *     description="Modelo de método de pagamento",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="code", type="string", example="corporate_card"),
 *     @OA\Property(property="name", type="string", example="Cartão Corporativo"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Pagamento realizado com cartão corporativo"),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="sort_order", type="integer", example=1),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class PaymentMethodController extends Controller
{
    use ResponseHelpers;

    /**
     * @OA\Get(
     *     path="/api/v1/payment-methods",
     *     summary="Lista métodos de pagamento",
     *     description="Retorna lista de métodos de pagamento com paginação, busca e filtros",
     *     tags={"PaymentMethods"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Página (padrão: 1)",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, example=1)
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         description="Registros por página (padrão: 20, máximo: 100)",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, example=20)
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Busca por nome ou código",
     *         required=false,
     *         @OA\Schema(type="string", example="Cartão")
     *     ),
     *     @OA\Parameter(
     *         name="filter_status",
     *         in="query",
     *         description="Filtrar por status: 'all', 'active' (ativas), 'inactive' (inativas)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"all", "active", "inactive"}, example="active")
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         description="Ordenação (ex: name,-sort_order)",
     *         required=false,
     *         @OA\Schema(type="string", example="name,-sort_order")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de métodos retornada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean", example=true),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/PaymentMethod")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Não autorizado"
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->get('pageSize', 20), 100);
        $search = $request->get('search');
        $filterStatus = $request->get('filter_status'); // 'all', 'active', 'inactive'

        $query = PaymentMethod::query();

        // Filtro de busca (nome ou código)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        // Filtro de status (ativo/inativo)
        if ($filterStatus === 'active') {
            $query->where('is_active', true);
        } elseif ($filterStatus === 'inactive') {
            $query->where('is_active', false);
        }

        // Ordenação PO-UI
        if ($request->has('order')) {
            $orderFields = explode(',', $request->get('order'));
            foreach ($orderFields as $field) {
                if (str_starts_with($field, '-')) {
                    $query->orderBy(substr($field, 1), 'desc');
                } else {
                    $query->orderBy($field, 'asc');
                }
            }
        } else {
            // Ordenação padrão: sort_order, depois nome
            $query->orderBy('sort_order')->orderBy('name');
        }

        // Paginação PO-UI
        $page = (int) $request->get('page', 1);
        $methods = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'hasNext' => $methods->hasMorePages(),
            'items' => $methods->items()
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/payment-methods/{id}",
     *     summary="Busca método de pagamento por ID",
     *     description="Retorna um método de pagamento específico",
     *     tags={"PaymentMethods"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do método",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Método encontrado",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/PaymentMethod")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Método não encontrado"
     *     )
     * )
     */
    public function show(int $id): JsonResponse
    {
        $method = PaymentMethod::find($id);

        if (!$method) {
            return $this->notFoundResponse('Método de pagamento não encontrado');
        }

        return response()->json([
            'success' => true,
            'data' => $method
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/payment-methods",
     *     summary="Criar método de pagamento",
     *     description="Cria um novo método de pagamento",
     *     tags={"PaymentMethods"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "code"},
     *             @OA\Property(property="name", type="string", example="Cartão Corporativo"),
     *             @OA\Property(property="code", type="string", example="corporate_card"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Pagamento realizado com cartão corporativo"),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="sort_order", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Método criado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/PaymentMethod")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados inválidos"
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|min:2',
            'code' => 'required|string|max:255|unique:payment_methods,code',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0'
        ]);

        $method = PaymentMethod::create($validated);

        return response()->json([
            'success' => true,
            'data' => $method
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/payment-methods/{id}",
     *     summary="Atualizar método de pagamento",
     *     description="Atualiza um método de pagamento existente",
     *     tags={"PaymentMethods"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do método",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Cartão Corporativo"),
     *             @OA\Property(property="code", type="string", example="corporate_card"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Pagamento realizado com cartão corporativo"),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="sort_order", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Método atualizado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/PaymentMethod")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Método não encontrado"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados inválidos"
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $method = PaymentMethod::find($id);

        if (!$method) {
            return $this->notFoundResponse('Método de pagamento não encontrado');
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|min:2',
            'code' => 'sometimes|string|max:255|unique:payment_methods,code,' . $id,
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0'
        ]);

        $method->update($validated);
        $method->refresh();

        return response()->json([
            'success' => true,
            'data' => $method
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/payment-methods/{id}",
     *     summary="Excluir método de pagamento",
     *     description="Exclui um método de pagamento. Não permite excluir se houver despesas vinculadas",
     *     tags={"PaymentMethods"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do método",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Método excluído com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Método excluído com sucesso")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Método não encontrado"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Não é possível excluir (tem despesas vinculadas)"
     *     )
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $method = PaymentMethod::find($id);

        if (!$method) {
            return $this->notFoundResponse('Método de pagamento não encontrado');
        }

        // Verificar se tem despesas vinculadas
        $expensesCount = $method->expensesCount();
        if ($expensesCount > 0) {
            return response()->json([
                'code' => 'HAS_EXPENSES',
                'type' => 'error',
                'message' => 'Não é possível excluir método de pagamento com despesas vinculadas',
                'detailMessage' => 'Existem ' . $expensesCount . ' despesa(s) vinculada(s) a este método'
            ], 422);
        }

        $method->delete();

        return response()->json([
            'success' => true,
            'message' => 'Método de pagamento excluído com sucesso'
        ]);
    }
}

