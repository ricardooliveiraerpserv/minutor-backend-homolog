<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Um ciclo excluído (soft delete) bloqueava criar outro planejamento para o mesmo ano
 * (unique company_id+year contava as linhas excluídas → erro 500). A validação já
 * ignora excluídos; o índice passa a ignorar também.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE okr_cycles DROP CONSTRAINT IF EXISTS okr_cycles_company_id_year_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS okr_cycles_company_year_active_unique ON okr_cycles (company_id, year) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS okr_cycles_company_year_active_unique');
        DB::statement('ALTER TABLE okr_cycles ADD CONSTRAINT okr_cycles_company_id_year_unique UNIQUE (company_id, year)');
    }
};
