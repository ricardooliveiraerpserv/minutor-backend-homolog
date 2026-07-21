<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona a tela "Clientes Inativos" (/relatorios/clientes-inativos) no grupo
 * Relatórios do menu administrativo (admin). Idempotente.
 */
return new class extends Migration
{
    private const SCREEN = '/relatorios/clientes-inativos';

    public function up(): void
    {
        $this->mutate(function (array &$children) {
            foreach ($children as $c) {
                if (($c['screen'] ?? null) === self::SCREEN) {
                    return; // já existe
                }
            }
            $children[] = [
                'id' => 'n_administrativo_clientes_inativos',
                'label' => 'Clientes Inativos',
                'icon' => 'UserX',
                'screen' => self::SCREEN,
            ];
        });
    }

    public function down(): void
    {
        $this->mutate(function (array &$children) {
            $children = array_values(array_filter($children, fn ($c) => ($c['screen'] ?? null) !== self::SCREEN));
        });
    }

    private function mutate(callable $fn): void
    {
        $row = DB::table('nav_modules')->where('key', 'administrativo')->first();
        if (! $row) {
            return;
        }
        $items = json_decode($row->items ?? '[]', true) ?: [];
        foreach ($items as &$g) {
            if (($g['label'] ?? null) === 'Relatórios' && isset($g['children'])) {
                $children = $g['children'];
                $fn($children);
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
