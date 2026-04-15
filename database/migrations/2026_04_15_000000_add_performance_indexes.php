<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Índice composto para a query batch de timesheets:
        // WHERE project_id IN (...) AND status != 'rejected' GROUP BY project_id
        // Covering index: evita leitura da tabela para somar effort_minutes
        if (!$this->indexExists('timesheets', 'timesheets_project_status_effort_idx')) {
            DB::statement('CREATE INDEX timesheets_project_status_effort_idx ON timesheets (project_id, status, effort_minutes)');
        }

        // Índice em projects.name para ORDER BY name (padrão da listagem)
        if (!$this->indexExists('projects', 'projects_name_idx')) {
            DB::statement('CREATE INDEX projects_name_idx ON projects (name)');
        }

        // Índice em projects.status para filtros frequentes
        if (!$this->indexExists('projects', 'projects_status_deleted_idx')) {
            DB::statement('CREATE INDEX projects_status_deleted_idx ON projects (status, deleted_at)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS timesheets_project_status_effort_idx');
        DB::statement('DROP INDEX IF EXISTS projects_name_idx');
        DB::statement('DROP INDEX IF EXISTS projects_status_deleted_idx');
    }

    private function indexExists(string $table, string $index): bool
    {
        $count = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $index]
        );
        return $count && $count->cnt > 0;
    }
};
