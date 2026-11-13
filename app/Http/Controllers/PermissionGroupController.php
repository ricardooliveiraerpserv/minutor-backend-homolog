<?php

namespace App\Http\Controllers;

use App\Models\PermissionGroup;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionGroupController extends Controller
{
    /**
     * Grupos de Permissões concedem permissões de BACKEND (ex.: environments.admin) —
     * só administrador pode ver/criar/editar/vincular (igual às demais rotas admin).
     */
    private function denyIfNotAdmin(): ?JsonResponse
    {
        $u = request()->user();
        return ($u && $u->isAdmin()) ? null : response()->json(['message' => 'Apenas administradores.'], 403);
    }

    public function index(): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) {
            return $deny;
        }

        $groups = PermissionGroup::withCount('users')->orderBy('name')->get();

        return response()->json([
            'items' => $groups,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) {
            return $deny;
        }

        $data = $request->validate([
            'name'        => 'required|string|max:100|unique:permission_groups,name',
            'description' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        $group = PermissionGroup::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => $data['permissions'] ?? [],
        ]);

        return response()->json(['data' => $group], 201);
    }

    public function show(PermissionGroup $permissionGroup): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) {
            return $deny;
        }

        $permissionGroup->load('users:id,name,email,type');

        return response()->json(['data' => $permissionGroup]);
    }

    public function update(Request $request, PermissionGroup $permissionGroup): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) {
            return $deny;
        }

        $data = $request->validate([
            'name'        => 'sometimes|string|max:100|unique:permission_groups,name,' . $permissionGroup->id,
            'description' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        $permissionGroup->update($data);

        return response()->json(['data' => $permissionGroup]);
    }

    public function destroy(PermissionGroup $permissionGroup): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) {
            return $deny;
        }

        $permissionGroup->delete();

        return response()->json(['message' => 'Grupo excluído com sucesso']);
    }

    public function users(PermissionGroup $permissionGroup): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) {
            return $deny;
        }

        $permissionGroup->load('users:id,name,email,type,coordinator_type,consultant_type');

        return response()->json(['items' => $permissionGroup->users]);
    }

    public function addUser(Request $request, PermissionGroup $permissionGroup): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) {
            return $deny;
        }

        $data = $request->validate(['user_id' => 'required|exists:users,id']);

        $permissionGroup->users()->syncWithoutDetaching([$data['user_id']]);

        return response()->json(['message' => 'Usuário adicionado ao grupo']);
    }

    public function removeUser(PermissionGroup $permissionGroup, User $user): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) {
            return $deny;
        }

        $permissionGroup->users()->detach($user->id);

        return response()->json(['message' => 'Usuário removido do grupo']);
    }

    /**
     * Retorna todas as permissões disponíveis para seleção na UI.
     */
    public function availablePermissions(): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin()) {
            return $deny;
        }

        $all = PermissionService::allPermissions();

        // Agrupa por prefixo (ex: timesheets.approve → timesheets)
        $grouped = [];
        foreach ($all as $perm) {
            $parts = explode('.', $perm);
            $category = $parts[0];
            $grouped[$category][] = $perm;
        }

        $result = [];
        foreach ($grouped as $category => $permissions) {
            $result[] = [
                'category'    => $category,
                'permissions' => $permissions,
            ];
        }

        return response()->json($result);
    }
}
