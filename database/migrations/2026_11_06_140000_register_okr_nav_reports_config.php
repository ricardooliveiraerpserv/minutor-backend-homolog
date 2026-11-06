<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Completa o menu da Gestão Estratégica: Relatórios e Configurações.
 * Acrescenta as 2 telas em nav_screens e as 2 folhas ao grupo g_okr já existente.
 * Idempotente.
 */
return new class extends Migration
{
    private array $screens = [
        ['key' => '/gestao-estrategica/relatorios',    'label' => 'Relatórios'],
        ['key' => '/gestao-estrategica/configuracoes', 'label' => 'Configurações'],
    ];

    private array $children = [
        ['id' => 'n_okr_rel', 'screen' => '/gestao-estrategica/relatorios',    'icon' => 'FileBarChart'],
        ['id' => 'n_okr_cfg', 'screen' => '/gestao-estrategica/configuracoes', 'icon' => 'Settings'],
    ];

    private array $modules = ['administrativo', 'administrativo__administrativo', 'servicos__coordenador_projetos', 'servicos__coordenador_sustentacao'];

    private array $profiles = [
        'admin', 'administrativo', 'coordenador_projetos', 'coordenador_sustentacao',
        'consultor_horista', 'consultor_fixo', 'consultor_banco_de_horas',
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->screens as $s) {
            DB::table('nav_screens')->updateOrInsert(
                ['key' => $s['key']],
                ['label' => $s['label'], 'route' => $s['key'], 'active' => true, 'profiles' => json_encode($this->profiles), 'updated_at' => $now, 'created_at' => $now]
            );
        }

        foreach ($this->modules as $moduleKey) {
            $row = DB::table('nav_modules')->where('key', $moduleKey)->first();
            if (!$row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            $changed = false;
            foreach ($items as &$node) {
                if (($node['id'] ?? null) !== 'g_okr') {
                    continue;
                }
                $existing = array_column($node['children'] ?? [], 'id');
                foreach ($this->children as $child) {
                    if (!in_array($child['id'], $existing, true)) {
                        $node['children'][] = $child;
                        $changed = true;
                    }
                }
            }
            unset($node);
            if ($changed) {
                DB::table('nav_modules')->where('key', $moduleKey)->update(['items' => json_encode($items), 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('nav_screens')->whereIn('key', array_column($this->screens, 'key'))->delete();

        $removeIds = array_column($this->children, 'id');
        foreach ($this->modules as $moduleKey) {
            $row = DB::table('nav_modules')->where('key', $moduleKey)->first();
            if (!$row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            foreach ($items as &$node) {
                if (($node['id'] ?? null) === 'g_okr' && !empty($node['children'])) {
                    $node['children'] = array_values(array_filter($node['children'], fn ($c) => !in_array($c['id'] ?? null, $removeIds, true)));
                }
            }
            unset($node);
            DB::table('nav_modules')->where('key', $moduleKey)->update(['items' => json_encode($items)]);
        }
    }
};
