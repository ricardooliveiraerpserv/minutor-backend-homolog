<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Múltiplos responsáveis por tarefa (usado pelas tarefas de reunião). A tarefa continua
 * ÚNICA (status/conclusão na própria `tasks`); o pivot só amplia quem é responsável.
 * `tasks.assigned_to` segue sendo o responsável "principal" (compat com Minhas Tarefas,
 * Calendário, etc.) — o pivot guarda todos, inclusive o principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task_assignees')) {
            Schema::create('task_assignees', function (Blueprint $t) {
                $t->id();
                $t->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['task_id', 'user_id']);
                $t->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignees');
    }
};
