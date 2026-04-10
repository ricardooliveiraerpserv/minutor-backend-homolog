<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // CONCURRENTLY não pode rodar dentro de transaction
    public $withinTransaction = false;

    public function up(): void
    {
        $tsHasSoftDelete   = Schema::hasColumn('timesheets', 'deleted_at');
        $expHasSoftDelete  = Schema::hasColumn('expenses', 'deleted_at');
        $projHasSoftDelete = Schema::hasColumn('projects', 'deleted_at');

        // ──────────────────────────────────────────────────────────
        // TIMESHEETS
        // ──────────────────────────────────────────────────────────
        $tsWhere = $tsHasSoftDelete ? 'WHERE deleted_at IS NULL' : '';

        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ts_active_user_date
            ON timesheets (user_id, date DESC) {$tsWhere}
        ");
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ts_active_project_status
            ON timesheets (project_id, status) {$tsWhere}
        ");
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ts_active_status_date
            ON timesheets (status, date DESC) {$tsWhere}
        ");

        // ──────────────────────────────────────────────────────────
        // EXPENSES
        // ──────────────────────────────────────────────────────────
        $expWhere = $expHasSoftDelete ? 'WHERE deleted_at IS NULL' : '';

        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_exp_active_user_date
            ON expenses (user_id, expense_date DESC) {$expWhere}
        ");
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_exp_active_project_status
            ON expenses (project_id, status) {$expWhere}
        ");
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_exp_active_status_date
            ON expenses (status, expense_date DESC) {$expWhere}
        ");

        // ──────────────────────────────────────────────────────────
        // PROJECTS
        // ──────────────────────────────────────────────────────────
        $projWhere = $projHasSoftDelete ? 'WHERE deleted_at IS NULL' : '';

        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_proj_active_customer_name
            ON projects (customer_id, name) {$projWhere}
        ");
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_proj_active_status_name
            ON projects (status, name) {$projWhere}
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_ts_active_user_date');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_ts_active_project_status');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_ts_active_status_date');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_exp_active_user_date');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_exp_active_project_status');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_exp_active_status_date');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_proj_active_customer_name');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_proj_active_status_name');
    }
};
