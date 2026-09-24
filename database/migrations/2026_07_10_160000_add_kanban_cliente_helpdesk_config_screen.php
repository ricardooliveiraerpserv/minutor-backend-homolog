<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expõe no menu a nova rotina do admin "Kanban do cliente" (Configurações do Help Desk).
 *
 * O menu do Help Desk é dirigido pelo Configurador: cada aba de config é (1) uma screen em
 * `nav_screens` (rótulo + perfis) e (2) um nó `screen` dentro da árvore `nav_modules.help_desk`.
 * Esta migration adiciona os dois, de forma IDEMPOTENTE (só cria se faltar) — admin-only, igual às
 * demais abas de config.
 */
return new class extends Migration
{
    private string $screenKey = '/help-desk/configuracoes?tab=kanban-cliente';

    public function up(): void
    {
        // 1) Screen (rótulo no menu + visibilidade por perfil). Admin-only, como as outras abas.
        if (DB::table('nav_screens')->where('key', $this->screenKey)->doesntExist()) {
            DB::table('nav_screens')->insert([
                'key'        => $this->screenKey,
                'label'      => 'Kanban do cliente',
                'route'      => $this->screenKey,
                'active'     => true,
                'profiles'   => json_encode(['admin']),
                'users'      => json_encode([]),
                'abilities'  => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2) Nó na árvore do módulo help_desk, dentro do grupo "Configurações Help Desk",
        //    logo após a aba "Status".
        $mod = DB::table('nav_modules')->where('key', 'help_desk')->first();
        if (!$mod) {
            return;
        }
        $items = json_decode($mod->items ?? '[]', true);
        if (!is_array($items)) {
            return;
        }

        $changed = false;
        foreach ($items as &$node) {
            if (($node['label'] ?? null) !== 'Configurações Help Desk' || !isset($node['children']) || !is_array($node['children'])) {
                continue;
            }
            // Já existe? não duplica.
            foreach ($node['children'] as $c) {
                if (($c['screen'] ?? null) === $this->screenKey) {
                    return; // idempotente: nada a fazer
                }
            }
            $newNode = ['id' => 'n_cfg_kanban_cliente', 'screen' => $this->screenKey, 'icon' => 'SquareKanban'];
            $out = [];
            $inserted = false;
            foreach ($node['children'] as $c) {
                $out[] = $c;
                if (!$inserted && ($c['screen'] ?? null) === '/help-desk/configuracoes?tab=status') {
                    $out[] = $newNode;
                    $inserted = true;
                }
            }
            if (!$inserted) {
                $out[] = $newNode; // fallback: fim do grupo
            }
            $node['children'] = $out;
            $changed = true;
            break;
        }
        unset($node);

        if ($changed) {
            DB::table('nav_modules')->where('key', 'help_desk')->update([
                'items'      => json_encode($items),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('nav_screens')->where('key', $this->screenKey)->delete();

        $mod = DB::table('nav_modules')->where('key', 'help_desk')->first();
        if (!$mod) {
            return;
        }
        $items = json_decode($mod->items ?? '[]', true);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as &$node) {
            if (!isset($node['children']) || !is_array($node['children'])) {
                continue;
            }
            $node['children'] = array_values(array_filter($node['children'], fn ($c) => ($c['screen'] ?? null) !== $this->screenKey));
        }
        unset($node);
        DB::table('nav_modules')->where('key', 'help_desk')->update(['items' => json_encode($items), 'updated_at' => now()]);
    }
};
