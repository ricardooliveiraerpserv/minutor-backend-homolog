<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Categoria e Precificação saíram do cadastro de Produto (migraram p/ a oportunidade).
 * As colunas legadas em crm_products precisam ser NULLABLE — senão criar produto sem
 * elas viola NOT NULL. DROP NOT NULL é no-op se já estiver nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE crm_products ALTER COLUMN categoria DROP NOT NULL');
        DB::statement('ALTER TABLE crm_products ALTER COLUMN tipo_precificacao DROP NOT NULL');
    }

    public function down(): void
    {
        // Não re-impõe NOT NULL (haveria linhas nulas legítimas).
    }
};
