<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\NavModule;
use App\Models\NavScreen;

/**
 * Registra a tela "Cliente × Executivo" (/clientes/executivos) no menu DB-driven:
 * nav_screen com perfis admin/administrativo/coordenador(+projetos/sustentacao) e
 * insere o item no grupo "Cadastros" do módulo "administrativo", logo após /clientes.
 * Idempotente (firstOrNew + checagem de duplicidade por screen).
 */
return new class extends Migration
{
    public function up(): void
    {
        $key   = '/clientes/executivos';
        $label = 'Cliente × Executivo';
        $now   = now();
        $profilesWanted = ['admin', 'administrativo', 'coordenador', 'coordenador_projetos', 'coordenador_sustentacao'];

        // 1) nav_screen + screen_actions
        $s = NavScreen::firstOrNew(['key' => $key]);
        $profiles = $s->exists ? ($s->profiles ?? []) : [];
        foreach ($profilesWanted as $p) {
            if (!in_array($p, $profiles, true)) {
                $profiles[] = $p;
            }
        }
        $s->profiles = array_values($profiles);
        $s->route    = $s->route ?: $key;
        if (!$s->exists) {
            $s->active = true;
        }
        if (empty($s->label)) {
            $s->label = $label;
        }
        $s->users = $s->users ?? [];
        $s->save();

        $i = 0;
        foreach (['view' => 'Visualizar', 'edit' => 'Editar'] as $ak => $al) {
            DB::table('screen_actions')->updateOrInsert(
                ['screen_key' => $key, 'action_key' => $ak],
                ['label' => $al, 'sort_order' => $i++, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        // 2) insere o item no grupo "Cadastros" do módulo administrativo (se ainda não estiver)
        $mod = NavModule::where('key', 'administrativo')->first();
        if (!$mod) {
            return;
        }
        $items = $mod->items ?? [];

        $already = false;
        foreach ($items as $g) {
            foreach (($g['children'] ?? []) as $c) {
                if (($c['screen'] ?? null) === $key) {
                    $already = true;
                    break 2;
                }
            }
        }
        if ($already) {
            return;
        }

        $changed = false;
        foreach ($items as $gi => $g) {
            if (($g['label'] ?? null) !== 'Cadastros') {
                continue;
            }
            $children = $g['children'] ?? [];
            // Insere logo após /clientes (âncora); senão, no fim do grupo.
            $pos = count($children);
            foreach ($children as $ci => $c) {
                if (($c['screen'] ?? null) === '/clientes') {
                    $pos = $ci + 1;
                    break;
                }
            }
            $newChild = ['id' => 'n_adm_clientexec_' . substr(md5($key), 0, 6), 'screen' => $key];
            array_splice($children, $pos, 0, [$newChild]);
            $items[$gi]['children'] = $children;
            $changed = true;
            break;
        }

        if ($changed) {
            $mod->items = $items;
            $mod->save();
        }
    }

    public function down(): void
    {
        $key = '/clientes/executivos';
        $mod = NavModule::where('key', 'administrativo')->first();
        if ($mod) {
            $items = $mod->items ?? [];
            foreach ($items as $gi => $g) {
                if (!isset($g['children'])) {
                    continue;
                }
                $items[$gi]['children'] = array_values(array_filter(
                    $g['children'],
                    fn ($c) => ($c['screen'] ?? null) !== $key
                ));
            }
            $mod->items = $items;
            $mod->save();
        }
        NavScreen::where('key', $key)->delete();
        DB::table('screen_actions')->where('screen_key', $key)->delete();
    }
};
