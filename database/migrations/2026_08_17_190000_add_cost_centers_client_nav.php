<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona "Centros de Custo" (/portal-cliente/centros-custo) ao MENU DO CLIENTE (nav_modules key=cliente),
 * como item de topo logo após a Home do Cliente. Label vem do nav-catalog do FE. Idempotente.
 */
return new class extends Migration
{
    private const SCREEN = '/portal-cliente/centros-custo';
    private const ANCHOR = '/portal-cliente';
    private const ITEM   = ['id' => 'n_cliente_centros_custo', 'icon' => 'Building2', 'screen' => self::SCREEN];

    public function up(): void
    {
        $row = DB::table('nav_modules')->where('key', 'cliente')->first();
        if (! $row) return;

        $items = json_decode($row->items ?? '[]', true) ?: [];

        // Já existe? (procura em qualquer nível)
        $json = json_encode($items);
        if (str_contains((string) $json, self::SCREEN)) return;

        // Insere logo após a Home do Cliente (/portal-cliente); se não achar, adiciona ao fim.
        $out = [];
        $inserted = false;
        foreach ($items as $it) {
            $out[] = $it;
            if (! $inserted && ($it['screen'] ?? null) === self::ANCHOR) {
                $out[] = self::ITEM;
                $inserted = true;
            }
        }
        if (! $inserted) $out[] = self::ITEM;

        DB::table('nav_modules')->where('key', 'cliente')->update([
            'items'      => json_encode($out, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('nav_modules')->where('key', 'cliente')->first();
        if (! $row) return;

        $items = json_decode($row->items ?? '[]', true) ?: [];
        $items = array_values(array_filter($items, fn ($it) => ($it['screen'] ?? null) !== self::SCREEN));

        DB::table('nav_modules')->where('key', 'cliente')->update([
            'items'      => json_encode($items, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
