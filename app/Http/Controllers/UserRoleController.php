<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * @OA\Tag(
 *     name="User Roles",
 *     description="Gerenciamento de Roles/Perfis dos usuários"
 * )
 */
class UserRoleController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/users/{user}/roles",
     *     summary="Listar roles do usuário",
     *     description="Lista todos os roles atribuídos a um usuário",
     *     tags={"User Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="user",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Roles do usuário",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object"),
     *                 @OA\Property(property="roles", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     )
     * )
     */
    public function getUserRoles(User $user): JsonResponse
    {
        $user->load('roles.permissions');
        
        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'roles' => $user->roles
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/users/{user}/roles",
     *     summary="Atribuir roles ao usuário",
     *     description="Adiciona um ou mais roles a um usuário",
     *     tags={"User Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="user",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"roles"},
     *             @OA\Property(property="roles", type="array", @OA\Items(type="string"), example={"Administrator", "Project Manager"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Roles atribuídos com sucesso"
     *     )
     * )
     */
    public function assignRoles(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name'
        ]);

        $user->assignRole($validated['roles']);
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Roles atribuídos com sucesso',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'roles' => $user->roles
            ]
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/users/{user}/roles",
     *     summary="Remover roles do usuário",
     *     description="Remove um ou mais roles de um usuário",
     *     tags={"User Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="user",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"roles"},
     *             @OA\Property(property="roles", type="array", @OA\Items(type="string"), example={"Consultant"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Roles removidos com sucesso"
     *     )
     * )
     */
    public function removeRoles(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name'
        ]);

        $user->removeRole($validated['roles']);
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Roles removidos com sucesso',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'roles' => $user->roles
            ]
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/users/{user}/roles",
     *     summary="Sincronizar roles do usuário",
     *     description="Substitui todos os roles do usuário pelos especificados",
     *     tags={"User Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="user",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"roles"},
     *             @OA\Property(property="roles", type="array", @OA\Items(type="string"), example={"Project Manager"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Roles sincronizados com sucesso"
     *     )
     * )
     */
    public function syncRoles(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name'
        ]);

        $user->syncRoles($validated['roles']);
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Roles sincronizados com sucesso',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'roles' => $user->roles
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users/{user}/permissions",
     *     summary="Listar permissões do usuário",
     *     description="Lista todas as permissões do usuário (via roles e diretas)",
     *     tags={"User Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="user",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permissões do usuário"
     *     )
     * )
     */
    public function getUserPermissions(User $user): JsonResponse
    {
        $permissions = $user->getAllPermissions();
        
        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'permissions' => $permissions
            ]
        ]);
    }

    /**
     * Lista usuários com roles (método auxiliar - não exposto como rota)
     */
    public function listUsersWithRoles(Request $request): JsonResponse
    {
        $pageSize = min((int) $request->get('pageSize', 50), 200);
        $page = (int) $request->get('page', 1);

        $paginator = User::with('roles')
            ->orderBy('name')
            ->paginate($pageSize, ['id', 'name', 'email', 'created_at', 'updated_at'], 'page', $page);

        $items = $paginator->getCollection()->map(fn($user) => [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'roles'      => $user->roles->pluck('name')->toArray(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ]);

        return response()->json([
            'hasNext' => $paginator->hasMorePages(),
            'items'   => $items,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/users/{user}/permissions/check",
     *     summary="Verificar permissão do usuário",
     *     description="Verifica se o usuário tem uma permissão específica",
     *     tags={"User Roles"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="user",
     *         in="path",
     *         description="ID do usuário",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"permission"},
     *             @OA\Property(property="permission", type="string", example="projects.create")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Resultado da verificação de permissão"
     *     )
     * )
     */
    public function checkPermission(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'permission' => 'required|string|exists:permissions,name'
        ]);

        $hasPermission = $user->can($validated['permission']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'permission' => $validated['permission'],
                'has_permission' => $hasPermission
            ]
        ]);
    }
}
