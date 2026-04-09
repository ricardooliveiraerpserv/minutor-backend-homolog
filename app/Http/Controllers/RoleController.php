<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Validation\ValidationException;

/**
 * @OA\Tag(
 *     name="Roles",
 *     description="Gerenciamento de Roles/Perfis de usuário"
 * )
 */
class RoleController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/roles",
     *     summary="Listar roles",
     *     description="Lista roles do sistema com suporte a filtro, ordenação e paginação no padrão PO-UI",
     *     tags={"Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número da página",
     *         required=false,
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         description="Itens por página",
     *         required=false,
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         description="Ordenação (ex: name,-created_at)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Busca pelo nome do role",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="with_permissions",
     *         in="query",
     *         description="Incluir permissões do role",
     *         required=false,
     *         @OA\Schema(type="boolean", default=true)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de roles",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean"),
     *             @OA\Property(property="items", type="array", @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="guard_name", type="string"),
     *                 @OA\Property(property="permissions", type="array", @OA\Items(type="object"))
     *             ))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $withPermissions = $request->boolean('with_permissions', true);

        $query = Role::query();

        if ($withPermissions) {
            $query->with('permissions');
        }

        // Filtro por nome (search/filter)
        $search = $request->get('filter') ?? $request->get('search');
        if (!empty($search)) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Ordenação PO-UI
        if ($request->has('order')) {
            $orderFields = explode(',', $request->get('order'));
            foreach ($orderFields as $field) {
                $direction = 'asc';
                if (str_starts_with($field, '-')) {
                    $direction = 'desc';
                    $field = substr($field, 1);
                }

                $allowedFields = ['name', 'created_at', 'updated_at'];
                if (in_array($field, $allowedFields)) {
                    $query->orderBy($field, $direction);
                }
            }
        } else {
            $query->orderBy('name'); // Ordenação padrão
        }

        // Paginação PO-UI
        $pageSize = min((int) $request->get('pageSize', 10), 100);
        $page = (int) $request->get('page', 1);

        $roles = $query->paginate($pageSize, ['*'], 'page', $page);

        // Resposta PO-UI
        return response()->json([
            'hasNext' => $roles->hasMorePages(),
            'items' => $roles->items()
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/roles",
     *     summary="Criar role",
     *     description="Cria um novo role no sistema",
     *     tags={"Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="Manager"),
     *             @OA\Property(property="permissions", type="array", @OA\Items(type="string"), example={"users.view", "projects.create"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Role criado com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name'
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web'
        ]);

        if (isset($validated['permissions'])) {
            $role->givePermissionTo($validated['permissions']);
        }

        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => 'Role criado com sucesso',
            'data' => $role
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/roles/{id}",
     *     summary="Visualizar role",
     *     description="Exibe detalhes de um role específico",
     *     tags={"Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do role",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Detalhes do role",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object")
     *         )
     *     )
     * )
     */
    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');

        // Resposta PO-UI
        return response()->json($role);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/roles/{id}",
     *     summary="Atualizar role",
     *     description="Atualiza um role existente",
     *     tags={"Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do role",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Senior Manager"),
     *             @OA\Property(property="permissions", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Role atualizado com sucesso"
     *     )
     * )
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:roles,name,' . $role->id,
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name'
        ]);

        if (isset($validated['name'])) {
            $role->name = $validated['name'];
            $role->save();
        }

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => 'Role atualizado com sucesso',
            'data' => $role
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/roles/{id}",
     *     summary="Excluir role",
     *     description="Remove um role do sistema. Não é possível excluir os roles padrão: Administrator, Consultant e Project Manager.",
     *     tags={"Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do role",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Role excluído com sucesso"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Erro ao excluir role (role protegido ou possui usuários associados)"
     *     )
     * )
     */
    public function destroy(Role $role): JsonResponse
    {
        // Roles padrão do sistema que não podem ser excluídos
        $protectedRoles = ['Administrator', 'Consultant', 'Project Manager'];

        if (in_array($role->name, $protectedRoles)) {
            return response()->json([
                'success' => false,
                'message' => "Não é possível excluir este role. '{$role->name}' é um perfil padrão do sistema e não pode ser removido."
            ], 400);
        }

        // Verificar se há usuários com este role
        $usersCount = $role->users()->count();

        if ($usersCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Não é possível excluir este role. Há {$usersCount} usuário(s) utilizando este perfil."
            ], 400);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role excluído com sucesso'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/roles/{id}/permissions",
     *     summary="Listar permissões do role",
     *     description="Lista todas as permissões de um role específico",
     *     tags={"Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do role",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permissões do role"
     *     )
     * )
     */
    public function permissions(Role $role): JsonResponse
    {
        // Resposta PO-UI
        return response()->json([
            'hasNext' => false,
            'items' => $role->permissions
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/roles/{id}/permissions",
     *     summary="Atribuir permissões ao role",
     *     description="Adiciona permissões a um role",
     *     tags={"Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do role",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"permissions"},
     *             @OA\Property(property="permissions", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permissões atribuídas com sucesso"
     *     )
     * )
     */
    public function givePermissions(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name'
        ]);

        $role->givePermissionTo($validated['permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Permissões atribuídas com sucesso',
            'data' => $role->permissions
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/roles/{id}/permissions",
     *     summary="Remover permissões do role",
     *     description="Remove permissões de um role",
     *     tags={"Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do role",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"permissions"},
     *             @OA\Property(property="permissions", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permissões removidas com sucesso"
     *     )
     * )
     */
    public function revokePermissions(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name'
        ]);

        $role->revokePermissionTo($validated['permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Permissões removidas com sucesso',
            'data' => $role->permissions
        ]);
    }
}
