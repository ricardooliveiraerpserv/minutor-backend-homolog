<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\NavModule;
use App\Models\NavScreen;

/**
 * Adiciona a tela "Solicitações de Código-Fonte" (/help-desk/codigo-fonte-solicitacoes)
 * ao grupo "Help Desk" do módulo dinâmico help_desk (nav_modules), logo após
 * "Solicitar Código-Fonte". Idempotente: registra a nav_screen e insere o item só
 * se ainda não existir (não clobbera itens editados pelo Configurador).
 */
return new class extends Migration
{
    public function up(): void
    {
        $key   = '/help-desk/codigo-fonte-solicitacoes';
        $label = 'Solicitações de Código-Fonte';
        $now   = now();

        // 1) nav_screen + screen_actions (perfil admin)
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

        // 2) insere o item no grupo "Help Desk" do módulo help_desk (se ainda não estiver)
        $mod = NavModule::where('key', 'help_desk')->first();
        if (!$mod) return;
        $items = $mod->items ?? [];
        $changed = false;

        // já existe em algum grupo?
        $already = false;
        foreach ($items as $g) {
            foreach (($g['children'] ?? []) as $c) {
                if (($c['screen'] ?? null) === $key) { $already = true; break 2; }
            }
        }

        if (!$already) {
            foreach ($items as $gi => $g) {
                if (($g['label'] ?? null) !== 'Help Desk') continue;
                $children = $g['children'] ?? [];
                // posição: logo após "Solicitar Código-Fonte" se houver; senão, no fim
                $pos = count($children);
                foreach ($children as $ci => $c) {
                    if (($c['screen'] ?? null) === '/help-desk/codigo-fonte') { $pos = $ci + 1; break; }
                }
                $newChild = ['id' => 'n_hd_sr_' . substr(md5($key), 0, 6), 'screen' => $key];
                array_splice($children, $pos, 0, [$newChild]);
                $items[$gi]['children'] = $children;
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $mod->items = $items;
            $mod->save();
        }
    }

    public function down(): void
    {
        $key = '/help-desk/codigo-fonte-solicitacoes';
        $mod = NavModule::where('key', 'help_desk')->first();
        if ($mod) {
            $items = $mod->items ?? [];
            foreach ($items as $gi => $g) {
                if (!isset($g['children'])) continue;
                $items[$gi]['children'] = array_values(array_filter($g['children'], fn ($c) => ($c['screen'] ?? null) !== $key));
            }
            $mod->items = $items;
            $mod->save();
        }
        NavScreen::where('key', $key)->delete();
        DB::table('screen_actions')->where('screen_key', $key)->delete();
    }
};
