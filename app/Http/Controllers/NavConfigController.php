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

    /** Perfis do configurador (mesmo conjunto/ordem da tela) para o limite de abas. */
    private const TAB_PROFILES = [
        'admin', 'administrativo', 'coordenador_projetos', 'coordenador_sustentacao',
        'consultor_horista', 'consultor_banco_de_horas', 'consultor_fixo',
        'parceiro_gestor', 'parceiro_simples', 'cliente',
    ];

    /** Lê os limites de abas por perfil (SystemSetting) e os overrides por usuário. Admin. */
    public function tabsLimits(Request $request): JsonResponse
    {
        if ($resp = $this->denyIfNotAdmin($request)) return $resp;

        $profiles = [];
        foreach (self::TAB_PROFILES as $p) {
            $profiles[$p] = \App\Models\SystemSetting::get('tabs.max.' . $p); // null = usa default
        }
        $overrides = \App\Models\User::whereNotNull('max_tabs')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'max_tabs'])
            ->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'max_tabs' => (int) $u->max_tabs]);

        return response()->json([
            'default'   => \App\Models\User::TABS_LIMIT_DEFAULT,
            'cap'       => \App\Models\User::TABS_LIMIT_CAP,
            'profiles'  => $profiles,
            'overrides' => $overrides,
        ]);
    }

    /** Grava limites por perfil (SystemSetting) + overrides por usuário (users.max_tabs). Admin. */
    public function saveTabsLimits(Request $request): JsonResponse
    {
        if ($resp = $this->denyIfNotAdmin($request)) return $resp;

        $cap = \App\Models\User::TABS_LIMIT_CAP;
        $data = $request->validate([
            'profiles'              => 'array',
            'profiles.*'            => 'nullable|integer|min:1|max:' . $cap,
            'overrides'             => 'array',
            'overrides.*.user_id'   => 'required|integer|exists:users,id',
            'overrides.*.max_tabs'  => 'required|integer|min:1|max:' . $cap,
        ]);

        // Por perfil: valor → SystemSetting; null/ausente → limpa (volta ao default).
        foreach (self::TAB_PROFILES as $p) {
            $key = 'tabs.max.' . $p;
            $val = $data['profiles'][$p] ?? null;
            if ($val === null || $val === '') {
                \App\Models\SystemSetting::where('key', $key)->delete();
                \Illuminate\Support\Facades\Cache::forget(\App\Models\SystemSetting::CACHE_PREFIX . $key);
            } else {
                \App\Models\SystemSetting::set($key, (int) $val, 'integer', 'tabs', 'Máx. de abas do workspace para o perfil ' . $p);
            }
        }

        // Overrides por usuário: a lista enviada é o estado desejado completo.
        $ids = collect($data['overrides'] ?? [])->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        \App\Models\User::whereNotNull('max_tabs')->when($ids, fn ($q) => $q->whereNotIn('id', $ids))->update(['max_tabs' => null]);
        foreach (($data['overrides'] ?? []) as $o) {
            \App\Models\User::where('id', (int) $o['user_id'])->update(['max_tabs' => (int) $o['max_tabs']]);
        }

        return response()->json(['ok' => true]);
    }

    /** Query dos usuários de uma CATEGORIA de perfil do configurador (perfil granular). */
    private function usersOfConfiguradorProfile(string $p)
    {
        $q = \App\Models\User::query();
        return match ($p) {
            'admin'                    => $q->where('type', 'admin'),
            'administrativo'           => $q->where('type', 'administrativo'),
            'coordenador_projetos'     => $q->where('type', 'coordenador')->where('coordinator_type', 'projetos'),
            'coordenador_sustentacao'  => $q->where('type', 'coordenador')->where('coordinator_type', 'sustentacao'),
            'consultor_horista'        => $q->where('type', 'consultor')->where('consultant_type', 'horista'),
            'consultor_banco_de_horas' => $q->where('type', 'consultor')->whereIn('consultant_type', ['banco_de_horas', 'bh_fixo', 'bh_mensal']),
            'consultor_fixo'           => $q->where('type', 'consultor')->where('consultant_type', 'fixo'),
            'parceiro_gestor'          => $q->where('type', 'parceiro_admin')->where('is_executive', true),
            'parceiro_simples'         => $q->where('type', 'parceiro_admin')->where('is_executive', false),
            default                    => $q->whereRaw('1 = 0'),
        };
    }

    /**
     * Aplica um limite de abas a TODA uma CATEGORIA de perfil: grava o padrão (SystemSetting)
     * E zera os overrides individuais dos usuários da categoria — assim todos passam a seguir
     * o novo valor. Depois o admin pode voltar a definir individuais. Admin.
     */
    public function applyTabsLimitToProfile(Request $request): JsonResponse
    {
        if ($resp = $this->denyIfNotAdmin($request)) return $resp;

        $cap = \App\Models\User::TABS_LIMIT_CAP;
        $data = $request->validate([
            'profile'  => 'required|string|in:' . implode(',', self::TAB_PROFILES),
            'max_tabs' => 'nullable|integer|min:1|max:' . $cap,
        ]);

        $p   = $data['profile'];
        $val = $data['max_tabs'] ?? null;
        $key = 'tabs.max.' . $p;

        // Padrão da categoria.
        if ($val === null) {
            \App\Models\SystemSetting::where('key', $key)->delete();
            \Illuminate\Support\Facades\Cache::forget(\App\Models\SystemSetting::CACHE_PREFIX . $key);
        } else {
            \App\Models\SystemSetting::set($key, (int) $val, 'integer', 'tabs', 'Máx. de abas do workspace para o perfil ' . $p);
        }

        // Zera os individuais da categoria → todos seguem o padrão recém-definido.
        $applied = $this->usersOfConfiguradorProfile($p)->update(['max_tabs' => null]);

        return response()->json(['applied' => $applied]);
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
            'action_key'  => 'nullable|string|max:40',
        ]);
        // Respeita a chave enviada pelo FE (ações "de fábrica" do catálogo, ex.: resend_welcome) —
        // assim, excluir e recriar a ação mantém a chave que o backend verifica (screen.action:...).
        // Só gera pelo slug do rótulo quando não vier chave ou ela for inválida.
        $sent = trim((string) ($v['action_key'] ?? ''));
        if ($sent !== '' && preg_match('/^[a-z0-9][a-z0-9_.\-]{0,39}$/', $sent)) {
            if (ScreenAction::where('screen_key', $v['screen_key'])->where('action_key', $sent)->exists()) {
                return response()->json(['message' => 'Esta ação já existe no catálogo desta tela.'], 409);
            }
            $key = $sent;
        } else {
            $key = Str::slug($v['label'], '_') ?: ('act_' . Str::random(4));
            $base = $key; $i = 1;
            while (ScreenAction::where('screen_key', $v['screen_key'])->where('action_key', $key)->exists()) { $key = $base . '_' . (++$i); }
        }
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
