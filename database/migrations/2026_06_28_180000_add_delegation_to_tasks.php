<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delegação de tarefas: created_by (criador), assigned_to (responsável), completed_by (quem
 * concluiu). Sem múltiplos responsáveis nem workflow — controle leve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
        });

        // Backfill: tarefas existentes = criadas e atribuídas ao próprio dono (user_id).
        DB::statement('update tasks set created_by = user_id, assigned_to = user_id where created_by is null');
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('completed_by');
        });
    }
};
