<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Participantes CONVIDADOS do Diário do Projeto (chat). Por padrão só coordenadores/
 * consultores do projeto (+ admin) acessam; esta tabela permite liberar um USUÁRIO
 * específico (ex.: um parceiro) a ver/postar no Diário de um projeto. Gerido por
 * admin/coordenador do projeto na própria aba.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_message_participants')) return;

        Schema::create('project_message_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_message_participants');
    }
};
