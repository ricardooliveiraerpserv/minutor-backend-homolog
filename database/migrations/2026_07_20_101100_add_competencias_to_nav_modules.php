<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registra o Banco de Competências na árvore do Configurador (nav_modules).
 *
 * Para perfis com 2+ módulos (ex.: admin), o menu NÃO vem da NAV estática do
 * sidebar — vem de nav_modules.items (buildModuleNav). Sem esta linha, o grupo
 * "Banco de Competências" não aparece no menu do Administrativo. Idempotente.
 */
return new class extends Migration
{
    private const GROUP = [
        'id' => 'g_administrativo_competencias',
        'label' => 'Banco de Competências',
        'icon' => 'Star',
        'children' => [
            [
                'id' => 'n_administrativo_competencias_pesquisas',
                'label' => 'Pesquisas de Competências',
                'icon' => 'ListTodo',
                'screen' => '/competencias/pesquisas',
            ],
        ],
    ];

    public function up(): void
    {
        $row = DB::table('nav_modules')->where('key', 'administrativo')->first();
        if (! $row) {
            return;
        }
        $items = json_decode($row->items ?? '[]', true) ?: [];

        $exists = false;
        array_walk_recursive($items, function ($v, $k) use (&$exists) {
            if ($k === 'screen' && $v === '/competencias/pesquisas') {
                $exists = true;
            }
        });
        if ($exists) {
            return;
        }

        $items[] = self::GROUP;
        DB::table('nav_modules')->where('key', 'administrativo')->update([
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('nav_modules')->where('key', 'administrativo')->first();
        if (! $row) {
            return;
        }
        $items = json_decode($row->items ?? '[]', true) ?: [];
        $items = array_values(array_filter($items, fn ($g) => ($g['id'] ?? null) !== 'g_administrativo_competencias'));
        DB::table('nav_modules')->where('key', 'administrativo')->update([
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
