<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove o `label` FIXO do nó de menu de /relatorios/atividade-clientes. Com o
 * label do nó presente, a sidebar (n.label || label-da-tela) ignorava o rename
 * feito pelo Configurador (que edita o label da NavScreen). Sem ele, o nome cai
 * na NavScreen (editável pelo Configurador) e, na falta, no catálogo. Idempotente.
 */
return new class extends Migration
{
    private const SCREEN = '/relatorios/atividade-clientes';

    public function up(): void
    {
        $row = DB::table('nav_modules')->where('key', 'administrativo')->first();
        if (! $row) {
            return;
        }
        $items = json_decode($row->items ?? '[]', true) ?: [];
        $walk = function (array &$nodes) use (&$walk) {
            foreach ($nodes as &$n) {
                if (($n['screen'] ?? null) === self::SCREEN) {
                    unset($n['label']);
                }
                if (! empty($n['children'])) {
                    $walk($n['children']);
                }
            }
            unset($n);
        };
        $walk($items);
        DB::table('nav_modules')->where('key', 'administrativo')->update([
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Reversão: recoloca o label fixo.
        $row = DB::table('nav_modules')->where('key', 'administrativo')->first();
        if (! $row) {
            return;
        }
        $items = json_decode($row->items ?? '[]', true) ?: [];
        $walk = function (array &$nodes) use (&$walk) {
            foreach ($nodes as &$n) {
                if (($n['screen'] ?? null) === self::SCREEN) {
                    $n['label'] = 'Status de Clientes';
                }
                if (! empty($n['children'])) {
                    $walk($n['children']);
                }
            }
            unset($n);
        };
        $walk($items);
        DB::table('nav_modules')->where('key', 'administrativo')->update([
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
