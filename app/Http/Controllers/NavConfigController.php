<?php

namespace App\Http\Controllers;

use App\Models\NavModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Configurador de navegação (admin): CRUD de módulos + associação de itens de menu.
 * O catálogo de itens (telas) é definido no FE; aqui só guardamos as keys.
 */
class NavConfigController extends Controller
{
    private function denyIfNotAdmin(Request $request): ?JsonResponse
    {
        return $request->user()?->isAdmin() ? null : response()->json(['message' => 'Apenas administradores.'], 403);
    }

    /** Lista os módulos (ordenados) — consumido pela sidebar e pelo Configurador. */
    public function index(Request $request): JsonResponse
    {
        // leitura liberada a qualquer usuário autenticado (a sidebar precisa)
        return response()->json(['data' => NavModule::ordered()->map(fn ($m) => [
            'id'        => $m->id,
            'key'       => $m->key,
            'label'     => $m->label,
            'icon'      => $m->icon,
            'sort_order'=> $m->sort_order,
            'is_system' => $m->is_system,
            'active'    => $m->active,
            'items'     => $m->items ?? [],
        ])]);
    }

    /** Cria um módulo novo. */
    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;
        $v = $request->validate([
            'label' => 'required|string|max:60',
            'icon'  => 'nullable|string|max:40',
        ]);
        $key = Str::slug($v['label'], '_') ?: ('mod_' . Str::random(6));
        $base = $key; $i = 1;
        while (NavModule::where('key', $key)->exists()) { $key = $base . '_' . (++$i); }

        $m = NavModule::create([
            'key'        => $key,
            'label'      => $v['label'],
            'icon'       => $v['icon'] ?: 'LayoutGrid',
            'sort_order' => (int) (NavModule::max('sort_order') + 1),
            'is_system'  => false,
            'active'     => true,
            'items'      => [],
        ]);
        return response()->json(['data' => $m], 201);
    }

    /** Edita um módulo (rótulo, ícone, ativo, ordem, itens associados). */
    public function update(Request $request, NavModule $navModule): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;
        $v = $request->validate([
            'label'      => 'sometimes|string|max:60',
            'icon'       => 'sometimes|string|max:40',
            'active'     => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'items'      => 'sometimes|array',
            'items.*'    => 'string|max:60',
        ]);
        // módulo de sistema não pode ser desativado nem renomear a key (mas pode reordenar/icone/itens/label)
        if ($navModule->is_system && array_key_exists('active', $v) && $v['active'] === false) {
            unset($v['active']);
        }
        $navModule->fill($v)->save();
        return response()->json(['data' => $navModule]);
    }

    /** Exclui um módulo (só não-sistema). */
    public function destroy(Request $request, NavModule $navModule): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;
        if ($navModule->is_system) {
            return response()->json(['message' => 'Módulo de sistema não pode ser excluído.'], 422);
        }
        $navModule->delete();
        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Reordena os módulos (lista de ids na nova ordem). */
    public function reorder(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;
        $ids = (array) $request->input('ids', []);
        foreach ($ids as $i => $id) {
            NavModule::where('id', $id)->update(['sort_order' => $i + 1]);
        }
        return response()->json(['data' => NavModule::ordered()]);
    }
}
