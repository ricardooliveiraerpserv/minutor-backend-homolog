<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Atualiza a rota do item de menu de /relatorios/clientes-inativos →
 * /relatorios/atividade-clientes (a tela foi renomeada; "inativos" não faz mais
 * sentido — traz todos os clientes com situação). Idempotente.
 */
return new class extends Migration
{
    private const FROM = '/relatorios/clientes-inativos';
    private const TO = '/relatorios/atividade-clientes';

    public function up(): void
    {
        $this->swap(self::FROM, self::TO);
    }

    public function down(): void
    {
        $this->swap(self::TO, self::FROM);
    }

    private function swap(string $from, string $to): void
    {
        $row = DB::table('nav_modules')->where('key', 'administrativo')->first();
        if (! $row) {
            return;
        }
        $items = json_decode($row->items ?? '[]', true) ?: [];
        $walk = function (array &$nodes) use (&$walk, $from, $to) {
            foreach ($nodes as &$n) {
                if (($n['screen'] ?? null) === $from) {
                    $n['screen'] = $to;
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
