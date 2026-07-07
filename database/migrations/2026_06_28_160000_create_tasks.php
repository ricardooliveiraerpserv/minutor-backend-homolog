<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarefas rápidas (Smart To-Do) pessoais: título + data/hora + tipo + recorrência + vínculo
 * com cliente/projeto/contrato. Sempre escopadas ao user_id (sem compartilhamento nesta fase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->string('type', 20)->default('pessoal');       // pessoal | cliente | follow-up | interno
            $table->string('entity_type', 20)->nullable();        // customer | project | contract
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('recurrence_type', 12)->default('none'); // none | daily | weekly | monthly
            $table->unsignedSmallInteger('recurrence_interval')->default(1);
            $table->date('recurrence_end_date')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'completed']);
            $table->index(['user_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
