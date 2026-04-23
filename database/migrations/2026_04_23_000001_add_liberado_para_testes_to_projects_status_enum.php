<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // projects.status usa VARCHAR + CHECK constraint (não ENUM nativo do PostgreSQL)
        DB::statement("ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('awaiting_start','started','liberado_para_testes','paused','cancelled','finished'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('awaiting_start','started','paused','cancelled','finished'))");
    }
};
