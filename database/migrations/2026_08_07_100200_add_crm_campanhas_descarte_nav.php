<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\NavModule;
use App\Models\NavScreen;

/**
 * Registra as telas novas do CRM (Campanhas, Motivos de Descarte) no Configurador de
 * menus: cataloga em nav_screens (perfil admin) e ANEXA os nós ao grupo
 * "Configurações CRM" da árvore de cada módulo que já a tiver — idempotente e
 * NÃO-destrutivo (preserva customizações do admin; só adiciona o que faltar).
 *
 * Necessário porque o menu do módulo vem da árvore do Configurador (nav_modules),
 * não do NAV hardcoded do FE (esse é só fallback quando a árvore está vazia).
 */
return new class extends Migration
{
    public function up(): void
    {
        // key => [label, ícone lucide]
        $novas = [
            '/crm/campanhas'        => ['Campanhas', 'Megaphone'],
            '/crm/motivos-descarte' => ['Motivos de Descarte', 'XCircle'],
        ];

        // 1) cataloga as telas (nav_screens) + ações básicas, no perfil admin.
        $now = now();
        foreach ($novas as $key => [$label, $icon]) {
            $s = NavScreen::firstOrNew(['key' => $key]);
            $profiles = $s->exists ? ($s->profiles ?? []) : [];
            if (!in_array('admin', $profiles, true)) $profiles[] = 'admin';
            $s->profiles = array_values($profiles);
            $s->route = $s->route ?: $key;
            if (!$s->exists) $s->active = true;
            if (empty($s->label)) $s->label = $label;
            $s->users = $s->users ?? [];
            $s->save();
            $i = 0;
            foreach (['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'] as $ak => $al) {
                DB::table('screen_actions')->updateOrInsert(
                    ['screen_key' => $key, 'action_key' => $ak],
                    ['label' => $al, 'sort_order' => $i++, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }

        // 2) anexa aos módulos que têm o grupo "Configurações CRM" (ou que contêm /crm/motivos-perda).
        $hasScreen = function (array $nodes, string $screen) use (&$hasScreen): bool {
            foreach ($nodes as $n) {
                if (($n['screen'] ?? null) === $screen) return true;
                if (!empty($n['children']) && $hasScreen($n['children'], $screen)) return true;
            }
            return false;
        };

        foreach (NavModule::all() as $mod) {
            $items = $mod->items ?? [];
            if (!is_array($items) || !$items) continue;
            $changed = false;

            foreach ($items as &$node) {
                if (empty($node['children']) || !is_array($node['children'])) continue;
                $ehConfigCrm = (($node['label'] ?? '') === 'Configurações CRM')
                    || $hasScreen($node['children'], '/crm/motivos-perda');
                if (!$ehConfigCrm) continue;

                foreach ($novas as $key => [$label, $icon]) {
                    // idempotente: só adiciona se a tela ainda não existir em NENHUM lugar da árvore.
                    if ($hasScreen($items, $key)) continue;
                    $node['children'][] = [
                        'id'     => 'n_' . substr(md5($key), 0, 8),
                        'screen' => $key,
                        'label'  => $label,
                        'icon'   => $icon,
                    ];
                    $changed = true;
                }
            }
            unset($node);

            if ($changed) {
                $mod->items = $items;
                $mod->save();
            }
        }
    }

    public function down(): void
    {
        $keys = ['/crm/campanhas', '/crm/motivos-descarte'];
        $strip = function (array $nodes) use (&$strip, $keys): array {
            $out = [];
            foreach ($nodes as $n) {
                if (in_array($n['screen'] ?? null, $keys, true)) continue;
                if (!empty($n['children']) && is_array($n['children'])) $n['children'] = $strip($n['children']);
                $out[] = $n;
            }
            return $out;
        };
        foreach (NavModule::all() as $mod) {
            $items = $mod->items ?? [];
            if (!is_array($items) || !$items) continue;
            $mod->items = $strip($items);
            $mod->save();
        }
        NavScreen::whereIn('key', ['/crm/campanhas', '/crm/motivos-descarte'])->delete();
    }
};
