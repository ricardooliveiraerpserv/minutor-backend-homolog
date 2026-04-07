<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

/**
 * @OA\Tag(
 *     name="Permissions",
 *     description="Gerenciamento de Permissões do sistema"
 * )
 */
class PermissionController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/permissions",
     *     summary="Listar permissões",
     *     description="Lista todas as permissões do sistema",
     *     tags={"Permissions"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="grouped",
     *         in="query",
     *         description="Agrupar permissões por categoria",
     *         required=false,
     *         @OA\Schema(type="boolean", default=false)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de permissões",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="hasNext", type="boolean", example=false),
     *             @OA\Property(property="items", type="array", @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="guard_name", type="string")
     *             ))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $permissions = Permission::all();
        
        if ($request->boolean('grouped')) {
            $grouped = $this->groupPermissions($permissions);
            return response()->json([
                'hasNext' => false,
                'items' => $grouped
            ]);
        }
        
        // Resposta PO-UI
        return response()->json([
            'hasNext' => false,
            'items' => $permissions
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/permissions",
     *     summary="Criar permissão",
     *     description="Cria uma nova permissão no sistema",
     *     tags={"Permissions"},
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="projects.create")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Permissão criada com sucesso",
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
            'name' => 'required|string|max:255|unique:permissions,name',
        ]);

        $permission = Permission::create([
            'name' => $validated['name'],
            'guard_name' => 'web'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Permissão criada com sucesso',
            'data' => $permission
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/permissions/{id}",
     *     summary="Visualizar permissão",
     *     description="Exibe detalhes de uma permissão específica",
     *     tags={"Permissions"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da permissão",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Detalhes da permissão",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object")
     *         )
     *     )
     * )
     */
    public function show(Permission $permission): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $permission
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/permissions/{id}",
     *     summary="Atualizar permissão",
     *     description="Atualiza uma permissão existente",
     *     tags={"Permissions"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da permissão",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="projects.update")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permissão atualizada com sucesso"
     *     )
     * )
     */
    public function update(Request $request, Permission $permission): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name,' . $permission->id,
        ]);

        $permission->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Permissão atualizada com sucesso',
            'data' => $permission
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/permissions/{id}",
     *     summary="Excluir permissão",
     *     description="Remove uma permissão do sistema",
     *     tags={"Permissions"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID da permissão",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permissão excluída com sucesso"
     *     )
     * )
     */
    public function destroy(Permission $permission): JsonResponse
    {
        // Verificar se há roles usando esta permissão
        $rolesCount = $permission->roles()->count();
        
        if ($rolesCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Não é possível excluir esta permissão. Há {$rolesCount} role(s) utilizando esta permissão."
            ], 400);
        }

        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permissão excluída com sucesso'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/permissions/grouped",
     *     summary="Listar permissões agrupadas",
     *     description="Lista permissões agrupadas por categoria (admin, projects, hours, etc.)",
     *     tags={"Permissions"},
     *     security={{"sanctum": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Permissões agrupadas por categoria"
     *     )
     * )
     */
    public function grouped(): JsonResponse
    {
        $permissions = Permission::all();
        $grouped = $this->groupPermissions($permissions);
        
        return response()->json($grouped);
    }

    /**
     * Agrupa permissões por categoria baseado no prefixo
     */
    private function groupPermissions($permissions): array
    {
        $grouped = [];
        
        foreach ($permissions as $permission) {
            $parts = explode('.', $permission->name);
            $category = $parts[0] ?? 'other';
            
            if (!isset($grouped[$category])) {
                $grouped[$category] = [
                    'category' => $category,
                    'permissions' => []
                ];
            }
            
            $grouped[$category]['permissions'][] = $permission;
        }
        
        return array_values($grouped);
    }
}
