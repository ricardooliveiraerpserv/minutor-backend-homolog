<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une "Consulta de Competências" + "Pesquisas de Competências" num único item de
 * menu (Consulta → /competencias/dashboard; Pesquisas vira aba dentro dela).
 * Remove o item Pesquisas do grupo. Idempotente.
 */
return new class extends Migration
{
    private const MERGED = [
        ['id' => 'n_administrativo_competencias_dashboard', 'label' => 'Consulta de Competências', 'icon' => 'Search', 'screen' => '/competencias/dashboard'],
        ['id' => 'n_administrativo_competencias_contratacao', 'label' => 'Contratação', 'icon' => 'UserPlus', 'screen' => '/competencias/contratacao'],
        ['id' => 'n_administrativo_competencias_matriz', 'label' => 'Matriz & Formulários', 'icon' => 'GitBranch', 'screen' => '/competencias/matriz'],
    ];

    public function up(): void
    {
        $this->setChildren(self::MERGED);
    }

    public function down(): void
    {
        $c = self::MERGED;
        // reinsere Pesquisas logo após a Consulta
        array_splice($c, 1, 0, [
            ['id' => 'n_administrativo_competencias_pesquisas', 'label' => 'Pesquisas de Competências', 'icon' => 'ListTodo', 'screen' => '/competencias/pesquisas'],
        ]);
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
