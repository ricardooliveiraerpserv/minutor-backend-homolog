<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liberação de visualização do pipeline "Demandas e Projetos" (/contratos/pipeline).
 * Por USUÁRIO (qualquer perfil). Cada parte (demand = Requisição+Autorização; project =
 * Projetos em Execução) tem visibilidade própria + escopo de cliente próprio.
 * Ausência de linha = comportamento padrão do perfil (admin vê as duas; coordenador/
 * consultor só a de Projetos). Cada parte: client_scope 'all' | 'specific' (+ customer_ids).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pipeline_view_permissions')) return;

        Schema::create('pipeline_view_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->boolean('demand_visible')->default(false);
            $table->string('demand_client_scope', 10)->default('all'); // all | specific
            $table->json('demand_customer_ids')->nullable();

            $table->boolean('project_visible')->default(true);
            $table->string('project_client_scope', 10)->default('all'); // all | specific
            $table->json('project_customer_ids')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_view_permissions');
    }
};
