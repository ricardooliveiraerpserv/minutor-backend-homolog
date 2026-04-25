<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // ALTER TYPE não pode rodar dentro de transação
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TYPE projects_status ADD VALUE IF NOT EXISTS 'liberado_para_testes'");
    }

    public function down(): void
    {
        // PostgreSQL não permite remover valores de ENUM — sem rollback possível
    }
};
