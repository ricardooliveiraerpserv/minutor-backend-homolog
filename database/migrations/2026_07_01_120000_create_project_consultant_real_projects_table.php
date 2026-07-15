<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projetos reais escolhidos por consultor ao alocá-lo num projeto de INVESTIMENTO
 * (Investimento Projetos / Investimento Suporte) do cliente.
 *
 * - project_id      → o projeto de investimento (onde o consultor está alocado)
 * - user_id         → o consultor alocado
 * - real_project_id → o projeto real do cliente que ele pode apontar via esse investimento
 *
 * No apontamento de investimento, o campo "Projeto Real" oferece SÓ os reais
 * escolhidos aqui para aquele consultor naquele investimento.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_consultant_real_projects')) {
            return;
        }

        Schema::create('project_consultant_real_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('real_project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id', 'real_project_id'], 'pcrp_unique');
            $table->index(['project_id', 'user_id'], 'pcrp_project_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_consultant_real_projects');
    }
};
