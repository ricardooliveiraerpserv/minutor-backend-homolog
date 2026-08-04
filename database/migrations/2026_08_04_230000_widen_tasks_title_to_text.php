<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alarga tasks.title (varchar(500) → text) para permitir tarefas de reunião com
 * texto longo (pautas coladas, listas com bullets/caracteres especiais). Widening
 * é não-destrutivo (mantém os dados). Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tasks ALTER COLUMN title TYPE text');
    }

    public function down(): void
    {
        // Volta a varredura anterior só se couber (evita truncar dados existentes).
        DB::statement('ALTER TABLE tasks ALTER COLUMN title TYPE varchar(500)');
    }
};
