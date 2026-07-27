<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tira "Cofre de Ambientes" (/ambientes) do MENU enquanto validamos o layout — a rotina
 * é acessada de dentro do Cofre de Senhas. Remove só o item de nav_modules.items; mantém
 * nav_screens + screen_actions (permissões seguem definidas; a rota /ambientes segue ativa).
 * Idempotente. Reversível pelo Configurador de Menus (down é no-op de propósito).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('nav_modules')->get() as $row) {
            $items = json_decode($row->items ?? '[]', true) ?: [];
            $filtered = array_values(array_filter($items, fn ($it) => ($it['screen'] ?? null) !== '/ambientes'));
            if (count($filtered) !== count($items)) {
                DB::table('nav_modules')->where('id', $row->id)->update([
                    'items' => json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Menu é reconfigurável pelo Configurador de Menus — sem reversão automática.
    }
};
