<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes com VISÃO GLOBAL do projeto (nível projeto, não atividade).
 *
 * Um usuário do tipo cliente vinculado aqui enxerga o projeto inteiro na visão
 * em dias (cronograma, visão geral, follow-ups), mantendo as restrições de card:
 * só abre os cards onde está envolvido / é responsável; conversa e anexos só se
 * for responsável.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_client_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_client_viewers');
    }
};
