<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reabertura MENSAL (project_open_periods) passa a suportar:
 *  - GLOBAL: project_id NULL (todos os projetos do mês);
 *  - por USUÁRIO: user_id NULL = todos.
 * Escopo único = (year_month, projeto|global, usuário|todos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_open_periods')) {
            if (!Schema::hasColumn('project_open_periods', 'user_id')) {
                Schema::table('project_open_periods', function (Blueprint $table) {
                    $table->foreignId('user_id')->nullable()->after('project_id')->constrained('users')->nullOnDelete();
                });
            }
            // project_id vira nullable (null = reabertura mensal global).
            DB::statement('ALTER TABLE project_open_periods ALTER COLUMN project_id DROP NOT NULL');
        }
        // Troca a unique antiga (project_id, year_month) por índice de escopo (com global/usuário).
        DB::statement('ALTER TABLE project_open_periods DROP CONSTRAINT IF EXISTS project_open_periods_project_id_year_month_unique');
        DB::statement('DROP INDEX IF EXISTS project_open_periods_project_id_year_month_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS project_open_periods_scope_uq ON project_open_periods (year_month, COALESCE(project_id, 0), COALESCE(user_id, 0))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS project_open_periods_scope_uq');
        if (Schema::hasColumn('project_open_periods', 'user_id')) {
            Schema::table('project_open_periods', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
