<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permite CONTRATAÇÃO AVULSA (incluir direto pela rotina, sem candidato do Banco de
 * Competências): respondent_id passa a ser nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('skill_hire_cards', 'respondent_id')) {
            // Postgres: dropar NOT NULL da coluna (a FK permanece).
            DB::statement('ALTER TABLE skill_hire_cards ALTER COLUMN respondent_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        // Não recoloca NOT NULL (evita falhar se já houver cards avulsos).
    }
};
