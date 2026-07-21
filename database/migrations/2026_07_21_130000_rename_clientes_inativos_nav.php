<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renomeia o item de menu "Clientes Inativos" → "Atividade de Clientes"
 * (a tela agora traz todos os clientes com legenda Ativo/Inativo). Mesma rota.
 * Idempotente.
 */
return new class extends Migration
{
    private const SCREEN = '/relatorios/clientes-inativos';
    private const LABEL = 'Atividade de Clientes';

    public function up(): void
    {
        $this->relabel(self::LABEL);
    }

    public function down(): void
    {
        $this->relabel('Clientes Inativos');
    }

    private function relabel(string $label): void
    {
        $row = DB::table('nav_modules')->where('key', 'administrativo')->first();
        if (! $row) {
            return;
        }
        $items = json_decode($row->items ?? '[]', true) ?: [];
        $walk = function (array &$nodes) use (&$walk, $label) {
            foreach ($nodes as &$n) {
                if (($n['screen'] ?? null) === self::SCREEN) {
                    $n['label'] = $label;
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
