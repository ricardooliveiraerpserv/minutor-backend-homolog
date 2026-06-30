<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rotinas de Equipe: um grupo (task_group) define tarefas-modelo (task_group_items) que, todo dia,
 * geram tasks individuais para cada usuário vinculado (task_group_users), respeitando a recorrência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_groups', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 200);
            $table->text('descricao')->nullable();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('task_group_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('task_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['group_id', 'user_id']);
        });

        Schema::create('task_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('task_groups')->cascadeOnDelete();
            $table->string('titulo', 500);
            $table->string('tipo', 20)->default('interno');
            $table->string('priority', 10)->default('media');
            $table->string('recorrencia', 12)->default('daily');  // daily | weekly | monthly
            $table->json('recurrence_weekdays')->nullable();        // [0..6] p/ weekly / daily úteis
            $table->time('hora_padrao')->nullable();
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('group_item_id')->nullable()->after('id')->constrained('task_group_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('group_item_id'));
        Schema::dropIfExists('task_group_items');
        Schema::dropIfExists('task_group_users');
        Schema::dropIfExists('task_groups');
    }
};
