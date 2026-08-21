<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_task_responsaveis')) {
            Schema::create('crm_task_responsaveis', function (Blueprint $table) {
                $table->id();
                $table->foreignId('crm_task_id')->constrained('crm_tasks')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['crm_task_id', 'user_id']);
            });
        }

        // Backfill: cada tarefa que já tem responsavel_id vira 1 linha na pivot (compat).
        if (Schema::hasTable('crm_task_responsaveis') && Schema::hasTable('crm_tasks')) {
            DB::statement("INSERT INTO crm_task_responsaveis (crm_task_id, user_id, created_at, updated_at)
                SELECT t.id, t.responsavel_id, now(), now()
                FROM crm_tasks t
                WHERE t.responsavel_id IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM crm_task_responsaveis r WHERE r.crm_task_id = t.id AND r.user_id = t.responsavel_id)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_task_responsaveis');
    }
};
