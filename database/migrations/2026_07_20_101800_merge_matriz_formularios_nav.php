<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une as rotinas Matriz e Formulários num único item de menu ("Matriz &
 * Formulários" → /competencias/matriz; Formulários vira aba dentro dela).
 * Idempotente.
 */
return new class extends Migration
{
    private const CHILDREN = [
        ['id' => 'n_administrativo_competencias_dashboard', 'label' => 'Dashboard', 'icon' => 'LayoutDashboard', 'screen' => '/competencias/dashboard'],
        ['id' => 'n_administrativo_competencias_pesquisas', 'label' => 'Pesquisas de Competências', 'icon' => 'ListTodo', 'screen' => '/competencias/pesquisas'],
        ['id' => 'n_administrativo_competencias_matriz', 'label' => 'Matriz & Formulários', 'icon' => 'GitBranch', 'screen' => '/competencias/matriz'],
    ];

    public function up(): void
    {
        $this->setChildren(self::CHILDREN);
    }

    public function down(): void
    {
        $c = self::CHILDREN;
        $c[2]['label'] = 'Matriz';
        $c[] = ['id' => 'n_administrativo_competencias_formularios', 'label' => 'Formulários', 'icon' => 'FileText', 'screen' => '/competencias/formularios'];
        $this->setChildren($c);
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
