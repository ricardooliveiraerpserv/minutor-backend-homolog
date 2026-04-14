<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // CONCURRENTLY não pode rodar dentro de transaction — desabilita para esta migration
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_status
                ON projects(status) WHERE deleted_at IS NULL');

            DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_customer_id
                ON projects(customer_id) WHERE deleted_at IS NULL');

            DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_contract_type_id
                ON projects(contract_type_id) WHERE deleted_at IS NULL');

            DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_parent_id
                ON projects(parent_project_id) WHERE deleted_at IS NULL');

            DB::statement('CREATE INDEX IF NOT EXISTS idx_projects_status_customer
                ON projects(status, customer_id) WHERE deleted_at IS NULL');

            DB::statement('CREATE INDEX IF NOT EXISTS idx_timesheets_project_status
                ON timesheets(project_id, status) WHERE deleted_at IS NULL');

            DB::statement('CREATE INDEX IF NOT EXISTS idx_hour_contributions_project
                ON hour_contributions(project_id)');
        } else {
            Schema::table('projects', function (Blueprint $table) {
                $table->index('status',            'idx_projects_status');
                $table->index('customer_id',       'idx_projects_customer_id');
                $table->index('contract_type_id',  'idx_projects_contract_type_id');
                $table->index('parent_project_id', 'idx_projects_parent_id');
                $table->index(['status', 'customer_id'], 'idx_projects_status_customer');
            });

            Schema::table('timesheets', function (Blueprint $table) {
                $table->index(['project_id', 'status'], 'idx_timesheets_project_status');
            });

            Schema::table('hour_contributions', function (Blueprint $table) {
                $table->index('project_id', 'idx_hour_contributions_project');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_projects_status');
            DB::statement('DROP INDEX IF EXISTS idx_projects_customer_id');
            DB::statement('DROP INDEX IF EXISTS idx_projects_contract_type_id');
            DB::statement('DROP INDEX IF EXISTS idx_projects_parent_id');
            DB::statement('DROP INDEX IF EXISTS idx_projects_status_customer');
            DB::statement('DROP INDEX IF EXISTS idx_timesheets_project_status');
            DB::statement('DROP INDEX IF EXISTS idx_hour_contributions_project');
        } else {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropIndex('idx_projects_status');
                $table->dropIndex('idx_projects_customer_id');
                $table->dropIndex('idx_projects_contract_type_id');
                $table->dropIndex('idx_projects_parent_id');
                $table->dropIndex('idx_projects_status_customer');
            });

            Schema::table('timesheets', function (Blueprint $table) {
                $table->dropIndex('idx_timesheets_project_status');
            });

            Schema::table('hour_contributions', function (Blueprint $table) {
                $table->dropIndex('idx_hour_contributions_project');
            });
        }
    }
};
