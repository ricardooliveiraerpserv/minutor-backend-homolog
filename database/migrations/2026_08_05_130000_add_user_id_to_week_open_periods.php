<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reabertura semanal pode ser limitada a UM usuário (consultor). user_id null = todos.
 * Escopo único = (semana, projeto|global, usuário|todos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('week_open_periods') && !Schema::hasColumn('week_open_periods', 'user_id')) {
            Schema::table('week_open_periods', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('project_id')->constrained('users')->nullOnDelete();
            });
        }
        DB::statement('DROP INDEX IF EXISTS week_open_periods_project_week_uq');
        DB::statement('DROP INDEX IF EXISTS week_open_periods_global_week_uq');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS week_open_periods_scope_uq ON week_open_periods (week_start, COALESCE(project_id, 0), COALESCE(user_id, 0))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS week_open_periods_scope_uq');
        if (Schema::hasColumn('week_open_periods', 'user_id')) {
            Schema::table('week_open_periods', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
