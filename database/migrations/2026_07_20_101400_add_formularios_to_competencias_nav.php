<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inclui "Configuração de Formulários" no grupo Banco de Competências (Configurador).
 * Idempotente (reescreve os filhos do grupo).
 */
return new class extends Migration
{
    private const CHILDREN = [
        ['id' => 'n_administrativo_competencias_dashboard', 'label' => 'Dashboard', 'icon' => 'LayoutDashboard', 'screen' => '/competencias/dashboard'],
        ['id' => 'n_administrativo_competencias_pesquisas', 'label' => 'Pesquisas de Competências', 'icon' => 'ListTodo', 'screen' => '/competencias/pesquisas'],
        ['id' => 'n_administrativo_competencias_profissionais', 'label' => 'Profissionais', 'icon' => 'Users', 'screen' => '/competencias/profissionais'],
        ['id' => 'n_administrativo_competencias_matriz', 'label' => 'Matriz', 'icon' => 'GitBranch', 'screen' => '/competencias/matriz'],
        ['id' => 'n_administrativo_competencias_formularios', 'label' => 'Formulários', 'icon' => 'FileText', 'screen' => '/competencias/formularios'],
    ];

    public function up(): void
    {
        $this->setChildren(self::CHILDREN);
    }

    public function down(): void
    {
        $this->setChildren(array_values(array_filter(self::CHILDREN, fn ($c) => $c['screen'] !== '/competencias/formularios')));
    }

    private function setChildren(array $children): void
    {
        $row = DB::table('nav_modules')->where('key', 'administrativo')->first();
        if (! $row) {
            return;
        }
        $items = json_decode($row->items ?? '[]', true) ?: [];
        foreach ($items as &$g) {
            if (($g['id'] ?? null) === 'g_administrativo_competencias') {
                $g['children'] = $children;
            }
        }
        unset($g);
        DB::table('nav_modules')->where('key', 'administrativo')->update([
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
