<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurador — modelo árvore.
 *
 * Separa CONCEITOS:
 *   • Tela (nav_screens): entidade única (key, label, route, active, profiles, users).
 *     As PERMISSÕES vivem aqui — alterar impacta todas as ocorrências.
 *   • Estrutura (nav_modules.items): árvore de REFERÊNCIAS a telas. A mesma tela pode
 *     aparecer em vários módulos/subníveis sem duplicar configuração.
 *
 * Backfill: extrai as telas distintas dos items ricos existentes (merge de perfis/usuários
 * e "ativo se ativo em algum lugar") e reescreve cada módulo como [{id, screen}, ...].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nav_screens', function (Blueprint $t) {
            $t->id();
            $t->string('key', 160)->unique();   // = href da tela (catálogo)
            $t->string('label', 120)->nullable();
            $t->string('route', 200)->nullable();
            $t->boolean('active')->default(true);
            $t->json('profiles')->nullable();
            $t->json('users')->nullable();
            $t->timestamps();
        });

        // 1) Coletar telas distintas a partir dos items ricos atuais
        $screens = []; // key => ['label','active','profiles'=>[],'users'=>[]]
        $modules = DB::table('nav_modules')->orderBy('sort_order')->get();
        foreach ($modules as $mod) {
            $items = json_decode($mod->items ?? '[]', true) ?: [];
            foreach ($items as $it) {
                $k = $it['key'] ?? null;
                if (!$k) continue;
                if (!isset($screens[$k])) {
                    $screens[$k] = ['label' => $it['label'] ?? null, 'active' => false, 'profiles' => [], 'users' => []];
                }
                if (!empty($it['label']) && empty($screens[$k]['label'])) {
                    $screens[$k]['label'] = $it['label'];
                }
                // ativo se estiver ativo em QUALQUER ocorrência
                if (($it['active'] ?? true)) $screens[$k]['active'] = true;
                $screens[$k]['profiles'] = array_values(array_unique(array_merge($screens[$k]['profiles'], $it['profiles'] ?? [])));
                $users = array_map('intval', $it['users'] ?? []);
                $screens[$k]['users'] = array_values(array_unique(array_merge($screens[$k]['users'], $users)));
            }
        }

        // 2) Gravar as telas
        $now = now();
        foreach ($screens as $k => $s) {
            DB::table('nav_screens')->insert([
                'key'        => $k,
                'label'      => $s['label'],
                'route'      => $k,
                'active'     => $s['active'],
                'profiles'   => json_encode($s['profiles']),
                'users'      => json_encode($s['users']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 3) Converter cada módulo numa árvore de referências [{id, screen}]
        foreach ($modules as $mod) {
            $items = json_decode($mod->items ?? '[]', true) ?: [];
            $tree = [];
            $i = 0;
            foreach ($items as $it) {
                $k = $it['key'] ?? null;
                if (!$k) continue;
                $tree[] = ['id' => 'n_' . $mod->id . '_' . ($i++), 'screen' => $k];
            }
            DB::table('nav_modules')->where('id', $mod->id)->update(['items' => json_encode($tree)]);
        }
    }

    public function down(): void
    {
        // Re-embute a config da tela de volta nos items (perde estrutura de subníveis)
        if (Schema::hasTable('nav_screens')) {
            $byKey = DB::table('nav_screens')->get()->keyBy('key');
            foreach (DB::table('nav_modules')->get() as $mod) {
                $tree = json_decode($mod->items ?? '[]', true) ?: [];
                $flat = [];
                $walk = function ($nodes) use (&$walk, &$flat, $byKey) {
                    foreach ($nodes as $n) {
                        if (!empty($n['screen']) && isset($byKey[$n['screen']])) {
                            $s = $byKey[$n['screen']];
                            $flat[] = [
                                'key'      => $s->key,
                                'label'    => $s->label,
                                'active'   => (bool) $s->active,
                                'profiles' => json_decode($s->profiles ?? '[]', true) ?: [],
                                'users'    => json_decode($s->users ?? '[]', true) ?: [],
                            ];
                        }
                        if (!empty($n['children'])) $walk($n['children']);
                    }
                };
                $walk($tree);
                DB::table('nav_modules')->where('id', $mod->id)->update(['items' => json_encode($flat)]);
            }
            Schema::dropIfExists('nav_screens');
        }
    }
};
