<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Reunião: detalhar a reunião + definir tarefas/responsáveis.
 * Acesso ao módulo: admin/coordenador. Cada reunião só é visível aos ENVOLVIDOS
 * (participantes) + criador; admin vê tudo. As tarefas reusam a tabela `tasks`
 * (entity_type='meeting') pra aparecerem em "Minhas tarefas" do responsável.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meetings')) {
            Schema::create('meetings', function (Blueprint $t) {
                $t->id();
                $t->string('title', 250);
                $t->dateTime('meeting_date')->nullable();
                $t->string('location', 250)->nullable();
                $t->text('description')->nullable();   // pauta
                $t->text('notes')->nullable();         // anotações (só envolvidos)
                $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();
                $t->index('meeting_date');
            });
        }

        if (!Schema::hasTable('meeting_participants')) {
            Schema::create('meeting_participants', function (Blueprint $t) {
                $t->id();
                $t->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['meeting_id', 'user_id']);
                $t->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};
