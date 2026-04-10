<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CONCURRENTLY não pode rodar dentro de transaction
    public $withinTransaction = false;

    public function up(): void
    {
        // ──────────────────────────────────────────────────────────
        // TIMESHEETS — partial indexes (excluem soft-deleted rows)
        // ──────────────────────────────────────────────────────────

        // Listagem por usuário ordenada por data (query mais frequente)
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ts_active_user_date
            ON timesheets (user_id, date DESC)
            WHERE deleted_at IS NULL
        ");

        // Filtro por projeto + status (aprovações, relatórios)
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ts_active_project_status
            ON timesheets (project_id, status)
            WHERE deleted_at IS NULL
        ");

        // Fila de aprovação: status = 'pending' + data
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_ts_active_status_date
            ON timesheets (status, date DESC)
            WHERE deleted_at IS NULL
        ");

        // ──────────────────────────────────────────────────────────
        // EXPENSES — partial indexes
        // ──────────────────────────────────────────────────────────

        // Listagem por usuário ordenada por data
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_exp_active_user_date
            ON expenses (user_id, expense_date DESC)
            WHERE deleted_at IS NULL
        ");

        // Filtro por projeto + status (aprovações)
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_exp_active_project_status
            ON expenses (project_id, status)
            WHERE deleted_at IS NULL
        ");

        // Fila de aprovação: status pending/adjustment + data
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_exp_active_status_date
            ON expenses (status, expense_date DESC)
            WHERE deleted_at IS NULL
        ");

        // ──────────────────────────────────────────────────────────
        // PROJECTS — partial indexes
        // ──────────────────────────────────────────────────────────

        // Listagem por cliente (filtro mais comum no project-list)
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_proj_active_customer_name
            ON projects (customer_id, name)
            WHERE deleted_at IS NULL
        ");

        // Filtro por status na listagem de projetos
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_proj_active_status_name
            ON projects (status, name)
            WHERE deleted_at IS NULL
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
