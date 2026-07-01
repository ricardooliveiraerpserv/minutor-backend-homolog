<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\NavModule;
use App\Models\NavScreen;

/**
 * MENUS INDEPENDENTES POR PERFIL.
 *
 * Antes: administrativo/servicos/configurador eram COMPARTILHADOS (um módulo com vários perfis).
 * Editar (excluir/ocultar/reordenar) num perfil afetava os outros.
 *
 * Agora: cada perfil interno ganha a SUA CÓPIA (árvore própria, ids de nó novos). Os ORIGINAIS
 * passam a ser do Admin (profiles=['admin']). A sidebar de cada perfil lê a sua cópia; o gating
 * de tela (screen.profiles) segue valendo. Admin deixa de ver por bypass (ver keysForProfile).
 */
return new class extends Migration
{
    public function up(): void
    {
        $originals = NavModule::whereIn('key', ['administrativo', 'servicos', 'configurador'])->get();
        $screens = NavScreen::get()->keyBy('key');

        // perfis internos não-admin que recebem cópia
        $profiles = ['administrativo', 'coordenador_projetos', 'coordenador_sustentacao'];

        $counter = 0;
        $newId = function () use (&$counter) { return 'c' . str_pad((string) (++$counter), 5, '0', STR_PAD_LEFT); };

        // Clona a árvore filtrando aos nós que o perfil enxerga (screen.profiles inclui P);
        // pasta é mantida se tiver ≥1 filho mantido. Ids de nó são regenerados (independência).
        $cloneTree = function ($nodes, string $p) use (&$cloneTree, $screens, $newId) {
            $out = [];
            foreach ((array) $nodes as $n) {
                if (!empty($n['screen'])) {
                    $s = $screens->get($n['screen']);
                    if ($s && in_array($p, (array) ($s->profiles ?? []), true)) {
                        $node = ['id' => $newId(), 'screen' => $n['screen']];
                        if (!empty($n['children'])) {
                            $kids = $cloneTree($n['children'], $p);
                            if ($kids) $node['children'] = $kids;
                        }
                        $out[] = $node;
                    }
                } else {
                    // pasta
                    $kids = $cloneTree($n['children'] ?? [], $p);
                    if ($kids) {
                        $node = ['id' => $newId(), 'label' => $n['label'] ?? '', 'children' => $kids];
                        if (!empty($n['icon'])) $node['icon'] = $n['icon'];
                        $out[] = $node;
                    }
                }
            }
            return $out;
        };

        $sort = (int) NavModule::max('sort_order');
        foreach ($profiles as $p) {
            foreach ($originals as $m) {
                if (!in_array($p, (array) ($m->profiles ?? []), true)) continue; // este perfil não via esse módulo
                $items = $cloneTree($m->items ?? [], $p);
                NavModule::updateOrCreate(
                    ['key' => $m->key . '__' . $p],
                    [
                        'label'      => $m->label,
                        'icon'       => $m->icon,
                        'sort_order' => ++$sort,
                        'is_system'  => false,          // cópia do perfil é livre p/ editar/excluir
                        'active'     => true,
                        'profiles'   => [$p],
                        'items'      => $items,
                    ]
                );
            }
        }

        // Originais viram do Admin (deixam de ser compartilhados)
        foreach ($originals as $m) {
            $m->profiles = ['admin'];
            $m->save();
        }
    }

    public function down(): void
    {
        // Remove as cópias e devolve os perfis aos originais
        NavModule::where('key', 'like', '%\_\_%')->delete();
        $map = [
            'administrativo' => ['administrativo', 'coordenador_projetos', 'coordenador_sustentacao'],
            'servicos'       => ['coordenador_projetos', 'coordenador_sustentacao'],
            'configurador'   => [],
        ];
        foreach ($map as $key => $profs) {
            $m = NavModule::where('key', $key)->first();
            if ($m) { $m->profiles = $profs; $m->save(); }
        }
    }
};
