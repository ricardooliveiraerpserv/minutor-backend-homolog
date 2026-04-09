<?php

namespace App\Http\Controllers;

use App\Models\ProjectStatus;
use App\Http\Traits\ResponseHelpers;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Schema(
 *     schema="ProjectStatus",
 *     type="object",
 *     title="Project Status",
 *     description="Modelo de status de projeto",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="code", type="string", example="started"),
 *     @OA\Property(property="name", type="string", example="Iniciado"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Projeto em andamento"),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="sort_order", type="integer", example=1),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class ProjectStatusController extends Controller
{
    use ResponseHelpers;

    /**
     * @OA\Get(
     *     path="/api/v1/project-statuses",
     *     summary="Lista status de projetos",
     *     description="Retorna lista de status de projetos com paginação, busca e filtros",
     *     tags={"ProjectStatuses"},
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
     *         @OA\Schema(type="string", example="Iniciado")
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
     *         description="Lista de status retornada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean", example=true),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/ProjectStatus")
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

        $query = ProjectStatus::query();

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
        $statuses = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'hasNext' => $statuses->hasMorePages(),
            'items' => $statuses->items()
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/project-statuses/{id}",
     *     summary="Busca status por ID",
     *     description="Retorna um status específico",
     *     tags={"ProjectStatuses"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do status",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Status encontrado",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ProjectStatus")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Status não encontrado"
     *     )
     * )
     */
    public function show(int $id): JsonResponse
    {
        $status = ProjectStatus::find($id);

        if (!$status) {
            return $this->notFoundResponse('Status de projeto não encontrado');
        }

        return response()->json([
            'success' => true,
            'data' => $status
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/project-statuses",
     *     summary="Criar status de projeto",
     *     description="Cria um novo status de projeto",
     *     tags={"ProjectStatuses"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "code"},
     *             @OA\Property(property="name", type="string", example="Iniciado"),
     *             @OA\Property(property="code", type="string", example="started"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Projeto em andamento"),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="sort_order", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Status criado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ProjectStatus")
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
            'code' => 'required|string|max:255|unique:project_statuses,code',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0'
        ]);

        $status = ProjectStatus::create($validated);

        return response()->json([
            'success' => true,
            'data' => $status
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/project-statuses/{id}",
     *     summary="Atualizar status de projeto",
     *     description="Atualiza um status de projeto existente",
     *     tags={"ProjectStatuses"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do status",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Iniciado"),
     *             @OA\Property(property="code", type="string", example="started"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Projeto em andamento"),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="sort_order", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Status atualizado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ProjectStatus")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Status não encontrado"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados inválidos"
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $status = ProjectStatus::find($id);

        if (!$status) {
            return $this->notFoundResponse('Status de projeto não encontrado');
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|min:2',
            'code' => 'sometimes|string|max:255|unique:project_statuses,code,' . $id,
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0'
        ]);

        $status->update($validated);
        $status->refresh();

        return response()->json([
            'success' => true,
            'data' => $status
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/project-statuses/{id}",
     *     summary="Excluir status de projeto",
     *     description="Exclui um status de projeto. Não permite excluir se houver projetos vinculados",
     *     tags={"ProjectStatuses"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do status",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Status excluído com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Status excluído com sucesso")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Status não encontrado"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Não é possível excluir (tem projetos vinculados)"
     *     )
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $status = ProjectStatus::find($id);

        if (!$status) {
            return $this->notFoundResponse('Status de projeto não encontrado');
        }

        // Verificar se tem projetos vinculados
        $projectsCount = $status->projectsCount();
        if ($projectsCount > 0) {
            return response()->json([
                'code' => 'HAS_PROJECTS',
                'type' => 'error',
                'message' => 'Não é possível excluir status de projeto com projetos vinculados',
                'detailMessage' => 'Existem ' . $projectsCount . ' projeto(s) vinculado(s) a este status'
            ], 422);
        }

        $status->delete();

        return response()->json([
            'success' => true,
            'message' => 'Status de projeto excluído com sucesso'
        ]);
    }
}

