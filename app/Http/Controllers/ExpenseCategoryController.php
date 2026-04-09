<?php

namespace App\Http\Controllers;

use App\Constants\SystemDefaults;
use App\Models\ExpenseCategory;
use App\Http\Traits\ResponseHelpers;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Schema(
 *     schema="ExpenseCategory",
 *     type="object",
 *     title="Expense Category",
 *     description="Modelo de categoria de despesa",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Transporte"),
 *     @OA\Property(property="code", type="string", example="transport"),
 *     @OA\Property(property="description", type="string", example="Despesas relacionadas a transporte e locomoção"),
 *     @OA\Property(property="parent_id", type="integer", nullable=true, example=null),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="sort_order", type="integer", example=1),
 *     @OA\Property(property="full_path", type="string", example="Transporte"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class ExpenseCategoryController extends Controller
{
    use ResponseHelpers;

    /**
     * @OA\Get(
     *     path="/api/v1/expense-categories",
     *     summary="Lista categorias de despesas",
     *     description="Retorna lista de categorias de despesas com paginação, busca e filtros",
     *     tags={"ExpenseCategories"},
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
     *         @OA\Schema(type="string", example="Transporte")
     *     ),
     *     @OA\Parameter(
     *         name="filter_type",
     *         in="query",
     *         description="Filtrar por tipo: 'all', 'main' (categorias principais), 'sub' (subcategorias)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"all", "main", "sub"}, example="main")
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
     *         description="Lista de categorias retornada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean", example=true),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/ExpenseCategory")
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
        $filterType = $request->get('filter_type'); // 'all', 'main', 'sub'
        $filterStatus = $request->get('filter_status'); // 'all', 'active', 'inactive'

        $query = ExpenseCategory::query();

        // Filtro de busca (nome ou código)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        // Filtro de tipo (categoria principal ou subcategoria)
        if ($filterType === 'main') {
            $query->whereNull('parent_id');
        } elseif ($filterType === 'sub') {
            $query->whereNotNull('parent_id');
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
        $categories = $query->paginate($perPage, ['*'], 'page', $page);

        // Adicionar full_path para cada categoria
        $categories->each(function ($category) {
            $category->full_path = $category->getFullPathAttribute();
        });

        return response()->json([
            'hasNext' => $categories->hasMorePages(),
            'items' => $categories->items()
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/expense-categories/{id}",
     *     summary="Busca categoria por ID",
     *     description="Retorna uma categoria específica com suas subcategorias",
     *     tags={"ExpenseCategories"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da categoria",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Categoria encontrada",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ExpenseCategory")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Categoria não encontrada"
     *     )
     * )
     */
    public function show(int $id): JsonResponse
    {
        $category = ExpenseCategory::with(['parent', 'children'])
            ->find($id);

        if (!$category) {
            return $this->notFoundResponse('Categoria não encontrada');
        }

        // Adicionar full_path
        $category->full_path = $category->getFullPathAttribute();

        return response()->json([
            'success' => true,
            'data' => $category
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/expense-categories/tree",
     *     summary="Árvore de categorias",
     *     description="Retorna estrutura hierárquica completa das categorias",
     *     tags={"ExpenseCategories"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Árvore de categorias",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     allOf={
     *                         @OA\Schema(ref="#/components/schemas/ExpenseCategory"),
     *                         @OA\Schema(
     *                             @OA\Property(
     *                                 property="children",
     *                                 type="array",
     *                                 @OA\Items(ref="#/components/schemas/ExpenseCategory")
     *                             )
     *                         )
     *                     }
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function tree(): JsonResponse
    {
        $categories = ExpenseCategory::with(['children' => function ($query) {
            $query->active()->orderBy('sort_order');
        }])
            ->active()
            ->mainCategories()
            ->get();

        // Adicionar full_path para cada categoria e suas filhas
        $categories->each(function ($category) {
            $category->full_path = $category->getFullPathAttribute();

            if ($category->children) {
                $category->children->each(function ($child) {
                    $child->full_path = $child->getFullPathAttribute();
                });
            }
        });

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/expense-categories/main",
     *     summary="Categorias principais",
     *     description="Retorna apenas categorias principais (sem pai)",
     *     tags={"ExpenseCategories"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Categorias principais",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean", example=false),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/ExpenseCategory")
     *             )
     *         )
     *     )
     * )
     */
    public function main(): JsonResponse
    {
        $categories = ExpenseCategory::active()
            ->mainCategories()
            ->get();

        $categories->each(function ($category) {
            $category->full_path = $category->getFullPathAttribute();
        });

        return response()->json([
            'hasNext' => false,
            'items' => $categories
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/expense-categories/{parentId}/subcategories",
     *     summary="Subcategorias",
     *     description="Retorna subcategorias de uma categoria específica",
     *     tags={"ExpenseCategories"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="parentId",
     *         in="path",
     *         description="ID da categoria pai",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Subcategorias encontradas",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean", example=false),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/ExpenseCategory")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Categoria pai não encontrada"
     *     )
     * )
     */
    public function subcategories(int $parentId): JsonResponse
    {
        // Verificar se a categoria pai existe
        $parent = ExpenseCategory::find($parentId);
        if (!$parent) {
            return $this->notFoundResponse('Categoria pai não encontrada');
        }

        $subcategories = ExpenseCategory::where('parent_id', $parentId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $subcategories->each(function ($category) {
            $category->full_path = $category->getFullPathAttribute();
        });

        return response()->json([
            'hasNext' => false,
            'items' => $subcategories
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/expense-categories",
     *     summary="Criar categoria de despesa",
     *     description="Cria uma nova categoria ou subcategoria de despesa",
     *     tags={"ExpenseCategories"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "code"},
     *             @OA\Property(property="name", type="string", example="Transporte"),
     *             @OA\Property(property="code", type="string", example="transport"),
     *             @OA\Property(property="description", type="string", example="Despesas relacionadas a transporte"),
     *             @OA\Property(property="parent_id", type="integer", nullable=true, example=null),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="sort_order", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Categoria criada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ExpenseCategory")
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
            'code' => 'required|string|max:255|unique:expense_categories,code',
            'description' => 'nullable|string|max:500',
            'parent_id' => 'nullable|integer|exists:expense_categories,id',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0'
        ]);

        // Validar que não pode ser pai de si mesmo
        if (isset($validated['parent_id']) && $validated['parent_id'] == $request->get('id')) {
            return response()->json([
                'code' => 'INVALID_PARENT',
                'type' => 'error',
                'message' => 'Uma categoria não pode ser pai de si mesma',
                'detailMessage' => 'O parent_id não pode ser o mesmo ID da categoria'
            ], 422);
        }

        // Validar que categoria pai existe e está ativa
        if (isset($validated['parent_id'])) {
            $parent = ExpenseCategory::find($validated['parent_id']);
            if (!$parent || !$parent->is_active) {
                return response()->json([
                    'code' => 'INVALID_PARENT',
                    'type' => 'error',
                    'message' => 'Categoria pai não encontrada ou inativa',
                    'detailMessage' => 'A categoria pai informada não existe ou está inativa'
                ], 422);
            }
        }

        $category = ExpenseCategory::create($validated);
        $category->full_path = $category->getFullPathAttribute();

        return response()->json([
            'success' => true,
            'data' => $category
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/expense-categories/{id}",
     *     summary="Atualizar categoria de despesa",
     *     description="Atualiza uma categoria ou subcategoria existente",
     *     tags={"ExpenseCategories"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da categoria",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Transporte"),
     *             @OA\Property(property="code", type="string", example="transport"),
     *             @OA\Property(property="description", type="string", example="Despesas relacionadas a transporte"),
     *             @OA\Property(property="parent_id", type="integer", nullable=true, example=null),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="sort_order", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Categoria atualizada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ExpenseCategory")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Categoria não encontrada"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados inválidos"
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = ExpenseCategory::find($id);

        if (!$category) {
            return $this->notFoundResponse('Categoria não encontrada');
        }

        // Verificar se está tentando alterar o código de uma categoria de sistema
        if ($request->has('code') && $request->get('code') !== $category->code) {
            if (SystemDefaults::isProtectedExpenseCategory($category->code)) {
                return response()->json([
                    'code' => 'SYSTEM_CATEGORY_PROTECTED',
                    'type' => 'error',
                    'message' => 'Não é possível alterar o código de uma categoria padrão do sistema',
                    'detailMessage' => 'Esta categoria é essencial para o funcionamento do sistema e seu código não pode ser alterado'
                ], 422);
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|min:2',
            'code' => 'sometimes|string|max:255|unique:expense_categories,code,' . $id,
            'description' => 'nullable|string|max:500',
            'parent_id' => 'nullable|integer|exists:expense_categories,id',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0'
        ]);

        // Validar que não pode ser pai de si mesmo
        if (isset($validated['parent_id']) && $validated['parent_id'] == $id) {
            return response()->json([
                'code' => 'INVALID_PARENT',
                'type' => 'error',
                'message' => 'Uma categoria não pode ser pai de si mesma',
                'detailMessage' => 'O parent_id não pode ser o mesmo ID da categoria'
            ], 422);
        }

        // Validar que não pode tornar uma categoria pai em subcategoria se ela tiver filhos
        if (isset($validated['parent_id']) && $validated['parent_id'] !== null) {
            if ($category->hasChildren()) {
                return response()->json([
                    'code' => 'HAS_CHILDREN',
                    'type' => 'error',
                    'message' => 'Categoria com subcategorias não pode se tornar subcategoria',
                    'detailMessage' => 'Remova as subcategorias antes de tornar esta categoria em subcategoria'
                ], 422);
            }
        }

        // Validar que categoria pai existe e está ativa
        if (isset($validated['parent_id']) && $validated['parent_id'] !== null) {
            $parent = ExpenseCategory::find($validated['parent_id']);
            if (!$parent || !$parent->is_active) {
                return response()->json([
                    'code' => 'INVALID_PARENT',
                    'type' => 'error',
                    'message' => 'Categoria pai não encontrada ou inativa',
                    'detailMessage' => 'A categoria pai informada não existe ou está inativa'
                ], 422);
            }
        }

        $category->update($validated);
        $category->refresh();
        $category->full_path = $category->getFullPathAttribute();

        return response()->json([
            'success' => true,
            'data' => $category
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/expense-categories/{id}",
     *     summary="Excluir categoria de despesa",
     *     description="Exclui uma categoria ou subcategoria. Não permite excluir se houver despesas vinculadas ou subcategorias",
     *     tags={"ExpenseCategories"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da categoria",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Categoria excluída com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Categoria excluída com sucesso")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Categoria não encontrada"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Não é possível excluir (tem despesas ou subcategorias vinculadas)"
     *     )
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $category = ExpenseCategory::find($id);

        if (!$category) {
            return $this->notFoundResponse('Categoria não encontrada');
        }

        // Verificar se é uma categoria padrão do sistema
        if (SystemDefaults::isProtectedExpenseCategory($category->code)) {
            return response()->json([
                'code' => 'SYSTEM_CATEGORY_PROTECTED',
                'type' => 'error',
                'message' => 'Não é possível excluir categoria padrão do sistema',
                'detailMessage' => 'Esta categoria é essencial para o funcionamento do sistema e não pode ser excluída'
            ], 422);
        }

        // Verificar se tem despesas vinculadas
        if ($category->expenses()->count() > 0) {
            return response()->json([
                'code' => 'HAS_EXPENSES',
                'type' => 'error',
                'message' => 'Não é possível excluir categoria com despesas vinculadas',
                'detailMessage' => 'Existem ' . $category->expenses()->count() . ' despesa(s) vinculada(s) a esta categoria'
            ], 422);
        }

        // Verificar se tem subcategorias
        if ($category->hasChildren()) {
            return response()->json([
                'code' => 'HAS_CHILDREN',
                'type' => 'error',
                'message' => 'Não é possível excluir categoria com subcategorias',
                'detailMessage' => 'Remova as subcategorias antes de excluir esta categoria'
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Categoria excluída com sucesso'
        ]);
    }
}
