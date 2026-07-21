<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expande o grupo "Banco de Competências" no Configurador (nav_modules) com as
 * telas da Fase 3: Dashboard, Profissionais e Matriz. Idempotente (reescreve os
 * filhos do grupo).
 */
return new class extends Migration
{
    private const CHILDREN = [
        ['id' => 'n_administrativo_competencias_dashboard', 'label' => 'Dashboard', 'icon' => 'LayoutDashboard', 'screen' => '/competencias/dashboard'],
        ['id' => 'n_administrativo_competencias_pesquisas', 'label' => 'Pesquisas de Competências', 'icon' => 'ListTodo', 'screen' => '/competencias/pesquisas'],
        ['id' => 'n_administrativo_competencias_profissionais', 'label' => 'Profissionais', 'icon' => 'Users', 'screen' => '/competencias/profissionais'],
        ['id' => 'n_administrativo_competencias_matriz', 'label' => 'Matriz', 'icon' => 'GitBranch', 'screen' => '/competencias/matriz'],
    ];

    public function up(): void
    {
        $this->setChildren(self::CHILDREN);
    }

    public function down(): void
    {
        // volta ao estado da migration anterior (só Pesquisas)
        $this->setChildren([
            ['id' => 'n_administrativo_competencias_pesquisas', 'label' => 'Pesquisas de Competências', 'icon' => 'ListTodo', 'screen' => '/competencias/pesquisas'],
        ]);
    }

    private function setChildren(array $children): void
    {
        $row = DB::table('nav_modules')->where('key', 'administrativo')->first();
        if (! $row) {
            return;
        }
        $items = json_decode($row->items ?? '[]', true) ?: [];
        $found = false;
        foreach ($items as &$g) {
            if (($g['id'] ?? null) === 'g_administrativo_competencias') {
                $g['children'] = $children;
                $found = true;
            }
        }
        unset($g);
        if (! $found) {
            $items[] = ['id' => 'g_administrativo_competencias', 'label' => 'Banco de Competências', 'icon' => 'Star', 'children' => $children];
        }
        DB::table('nav_modules')->where('key', 'administrativo')->update([
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
