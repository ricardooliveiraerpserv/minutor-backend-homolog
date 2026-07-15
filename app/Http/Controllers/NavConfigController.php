<?php

namespace App\Http\Controllers;

use App\Models\NavModule;
use App\Models\NavScreen;
use App\Models\ScreenAction;
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

    /** Saneia a árvore de referências: mantém só {id, screen?, label?, children?} recursivamente. */
    private function sanitizeTree($nodes): array
    {
        if (!is_array($nodes)) return [];
        $out = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            $node = ['id' => (string) ($n['id'] ?? uniqid('n_'))];
            if (!empty($n['screen'])) $node['screen'] = (string) $n['screen'];
            if (isset($n['label']) && $n['label'] !== '') $node['label'] = (string) $n['label'];
            if (!empty($n['icon'])) $node['icon'] = (string) $n['icon'];
            if (!empty($n['hidden'])) $node['hidden'] = true;   // ocultar por perfil (nó da cópia)
            // Override de VISIBILIDADE por usuário no nó (pasta/tela): libera ou esconde só p/ certos usuários.
            if (!empty($n['users'])) $node['users'] = array_values(array_map('intval', (array) $n['users']));
            if (!empty($n['deny_users'])) $node['deny_users'] = array_values(array_map('intval', (array) $n['deny_users']));
            if (!empty($n['children'])) $node['children'] = $this->sanitizeTree($n['children']);
            // nó precisa ser tela OU grupo (com label/children)
            if (isset($node['screen']) || isset($node['label']) || isset($node['children'])) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * Estrutura completa do menu — consumido pela sidebar e pelo Configurador.
     * Retorna os MÓDULOS (árvore de referências em `items`) e o registro de TELAS
     * (telas = entidade única com permissões). Leitura liberada a qualquer autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => NavModule::ordered()->map(fn ($m) => [
                'id'        => $m->id,
                'key'       => $m->key,
                'label'     => $m->label,
                'icon'      => $m->icon,
                'sort_order'=> $m->sort_order,
                'is_system' => $m->is_system,
                'active'    => $m->active,
                'profiles'  => $m->profiles ?? [],  // perfis que veem o módulo (admin = bypass)
                'items'     => $m->items ?? [],   // árvore de nós {id, screen?, label?, children?}
            ]),
            'screens' => NavScreen::orderBy('key')->get()->map(fn ($s) => [
                'key'      => $s->key,
                'label'    => $s->label,
                'route'    => $s->route,
                'active'    => $s->active,
                'profiles'  => $s->profiles ?? [],
                'users'     => $s->users ?? [],
                'abilities' => $s->abilities ?? new \stdClass,
            ]),
            'screen_actions' => \App\Models\ScreenAction::catalog() ?: new \stdClass,
        ]);
    }

    /** Ações negadas ao usuário logado, por tela — consumido pelo FE p/ esconder/desabilitar botões. */
    public function myDeniedActions(Request $request): JsonResponse
    {
        return response()->json(['data' => \App\Services\AccessControl::deniedActionsFor($request->user()) ?: new \stdClass]);
    }

    /** Upsert em lote das TELAS (permissões/ativo). Admin. Mantém consistência única por key. */
    public function saveScreens(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;
        $v = $request->validate([
            'screens'              => 'required|array',
            'screens.*.key'        => 'required|string|max:160',
            'screens.*.label'      => 'nullable|string|max:120',
            'screens.*.active'     => 'nullable|boolean',
            'screens.*.profiles'   => 'nullable|array',
            'screens.*.profiles.*' => 'string|max:30',
            'screens.*.users'      => 'nullable|array',
            'screens.*.users.*'    => 'integer',
            'screens.*.abilities'  => 'nullable|array',  // { action: { profiles[], users[], deny_users[] } }
        ]);
        foreach ($v['screens'] as $s) {
            NavScreen::updateOrCreate(
                ['key' => $s['key']],
                [
                    'label'     => $s['label'] ?? null,
                    'route'     => $s['key'],
                    'active'    => $s['active'] ?? true,
                    'profiles'  => array_values($s['profiles'] ?? []),
                    'users'     => array_values(array_map('intval', $s['users'] ?? [])),
                    'abilities' => $s['abilities'] ?? null,
                ]
            );
        }
        return response()->json(['data' => NavScreen::orderBy('key')->get()]);
    }

    /** Cria um módulo novo. */
    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;
        $v = $request->validate([
            'label'      => 'required|string|max:60',
            'icon'       => 'nullable|string|max:40',
            'profiles'   => 'nullable|array',
            'profiles.*' => 'string|max:30',
            'items'      => 'nullable|array',   // p/ copiar módulo existente (árvore inicial)
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
            'profiles'   => array_values($v['profiles'] ?? []),
            'items'      => $this->sanitizeTree($v['items'] ?? []),
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
            'profiles'   => 'sometimes|array',          // perfis que veem o módulo
            'profiles.*' => 'string|max:30',
            // items = ÁRVORE de referências: nós { id, screen?, label?, children?[] }.
            // Permissões NÃO ficam aqui — vivem na tela (nav_screens). Estrutura recursiva:
            // validação leve (array) + sanitização no model.
            'items'      => 'sometimes|array',
        ]);
        if (array_key_exists('items', $v)) {
            $v['items'] = $this->sanitizeTree($request->input('items', []));
        }
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
        // Guard: não excluir o ÚLTIMO módulo — só permite se houver mais de um.
        if (NavModule::count() <= 1) {
            return response()->json(['message' => 'Não é possível excluir o único módulo. Deve existir pelo menos um.'], 422);
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

    /** Adiciona uma ação ao catálogo de uma tela (screen_actions). Retorna a lista atualizada da tela. */
    public function addScreenAction(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;
        $v = $request->validate([
            'screen_key'  => 'required|string|max:160',
            'label'       => 'required|string|max:80',
            'description' => 'nullable|string|max:200',
        ]);
        $key = Str::slug($v['label'], '_') ?: ('act_' . Str::random(4));
        $base = $key; $i = 1;
        while (ScreenAction::where('screen_key', $v['screen_key'])->where('action_key', $key)->exists()) { $key = $base . '_' . (++$i); }
        $max = (int) ScreenAction::where('screen_key', $v['screen_key'])->max('sort_order');
        ScreenAction::create([
            'screen_key'  => $v['screen_key'],
            'action_key'  => $key,
            'label'       => $v['label'],
            'description' => $v['description'] ?? null,
            'sort_order'  => $max + 1,
        ]);
        return response()->json(['data' => ScreenAction::where('screen_key', $v['screen_key'])->orderBy('sort_order')->orderBy('id')->get()]);
    }

    /** Remove uma ação do catálogo de uma tela. Retorna a lista atualizada da tela. */
    public function deleteScreenAction(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAdmin($request)) return $deny;
        $v = $request->validate(['screen_key' => 'required|string|max:160', 'action_key' => 'required|string|max:40']);
        ScreenAction::where('screen_key', $v['screen_key'])->where('action_key', $v['action_key'])->delete();
        return response()->json(['data' => ScreenAction::where('screen_key', $v['screen_key'])->orderBy('sort_order')->orderBy('id')->get()]);
    }
}
