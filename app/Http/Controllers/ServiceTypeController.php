<?php

namespace App\Http\Controllers;

use App\Constants\SystemDefaults;
use App\Models\ServiceType;
use App\Http\Traits\ResponseHelpers;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Schema(
 *     schema="ServiceType",
 *     type="object",
 *     title="Service Type",
 *     description="Modelo de tipo de serviço",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="code", type="string", example="DEV_WEB"),
 *     @OA\Property(property="name", type="string", example="Desenvolvimento Web"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Desenvolvimento de aplicações web"),
 *     @OA\Property(property="active", type="boolean", example=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Tag(
 *     name="Service Types",
 *     description="Tipos de Serviço"
 * )
 */
class ServiceTypeController extends Controller
{
    use ResponseHelpers;

    /**
     * @OA\Get(
     *     path="/api/v1/service-types",
     *     summary="Lista tipos de serviços",
     *     description="Retorna lista de tipos de serviços com paginação, busca e filtros",
     *     tags={"Service Types"},
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
     *         @OA\Schema(type="string", example="Desenvolvimento")
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
     *         description="Ordenação (ex: name,-code)",
     *         required=false,
     *         @OA\Schema(type="string", example="name,-code")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de tipos retornada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean", example=true),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/ServiceType")
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

        $query = ServiceType::query();

        // Filtro de busca (nome ou código)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        // Filtro de status (ativo/inativo)
        if ($filterStatus === 'active') {
            $query->where('active', true);
        } elseif ($filterStatus === 'inactive') {
            $query->where('active', false);
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
            // Ordenação padrão: nome
            $query->orderBy('name');
        }

        // Paginação PO-UI
        $page = (int) $request->get('page', 1);
        $types = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'hasNext' => $types->hasMorePages(),
            'items' => $types->items()
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/service-types/{id}",
     *     summary="Busca tipo por ID",
     *     description="Retorna um tipo específico",
     *     tags={"Service Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do tipo",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tipo encontrado",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ServiceType")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Tipo não encontrado"
     *     )
     * )
     */
    public function show(int $id): JsonResponse
    {
        $type = ServiceType::find($id);

        if (!$type) {
            return $this->notFoundResponse('Tipo de serviço não encontrado');
        }

        return response()->json([
            'success' => true,
            'data' => $type
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/service-types",
     *     summary="Criar tipo de serviço",
     *     description="Cria um novo tipo de serviço",
     *     tags={"Service Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "code"},
     *             @OA\Property(property="name", type="string", example="Desenvolvimento Web"),
     *             @OA\Property(property="code", type="string", example="DEV_WEB"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Desenvolvimento de aplicações web"),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Tipo criado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ServiceType")
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
            'code' => 'required|string|max:255|unique:service_types,code',
            'description' => 'nullable|string|max:500',
            'active' => 'nullable|boolean',
        ]);

        $type = ServiceType::create($validated);

        return response()->json([
            'success' => true,
            'data' => $type
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/service-types/{id}",
     *     summary="Atualizar tipo de serviço",
     *     description="Atualiza um tipo de serviço existente",
     *     tags={"Service Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do tipo",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Desenvolvimento Web"),
     *             @OA\Property(property="code", type="string", example="DEV_WEB"),
     *             @OA\Property(property="description", type="string", nullable=true, example="Desenvolvimento de aplicações web"),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tipo atualizado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/ServiceType")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Tipo não encontrado"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dados inválidos"
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $type = ServiceType::find($id);

        if (!$type) {
            return $this->notFoundResponse('Tipo de serviço não encontrado');
        }

        // Verificar se está tentando alterar o código de um tipo de sistema
        if ($request->has('code') && $request->get('code') !== $type->code) {
            if (SystemDefaults::isProtectedServiceType($type->code)) {
                return response()->json([
                    'code' => 'SYSTEM_TYPE_PROTECTED',
                    'type' => 'error',
                    'message' => 'Não é possível alterar o código de um tipo de serviço padrão do sistema',
                    'detailMessage' => 'Este tipo de serviço é essencial para o funcionamento do sistema e seu código não pode ser alterado'
                ], 422);
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|min:2',
            'code' => 'sometimes|string|max:255|unique:service_types,code,' . $id,
            'description' => 'nullable|string|max:500',
            'active' => 'nullable|boolean',
        ]);

        $type->update($validated);
        $type->refresh();

        return response()->json([
            'success' => true,
            'data' => $type
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/service-types/{id}",
     *     summary="Excluir tipo de serviço",
     *     description="Exclui um tipo de serviço. Não permite excluir se houver projetos vinculados",
     *     tags={"Service Types"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do tipo",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tipo excluído com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Tipo excluído com sucesso")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Tipo não encontrado"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Não é possível excluir (tem projetos vinculados)"
     *     )
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $type = ServiceType::find($id);

        if (!$type) {
            return $this->notFoundResponse('Tipo de serviço não encontrado');
        }

        // Verificar se é um tipo de serviço padrão do sistema
        if (SystemDefaults::isProtectedServiceType($type->code)) {
            return response()->json([
                'code' => 'SYSTEM_TYPE_PROTECTED',
                'type' => 'error',
                'message' => 'Não é possível excluir tipo de serviço padrão do sistema',
                'detailMessage' => 'Este tipo de serviço é essencial para o funcionamento do sistema e não pode ser excluído'
            ], 422);
        }

        // Verificar se tem projetos vinculados
        $projectsCount = $type->projects()->count();
        if ($projectsCount > 0) {
            return response()->json([
                'code' => 'HAS_PROJECTS',
                'type' => 'error',
                'message' => 'Não é possível excluir tipo de serviço com projetos vinculados',
                'detailMessage' => 'Existem ' . $projectsCount . ' projeto(s) vinculado(s) a este tipo'
            ], 422);
        }

        $type->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tipo de serviço excluído com sucesso'
        ]);
    }
}
