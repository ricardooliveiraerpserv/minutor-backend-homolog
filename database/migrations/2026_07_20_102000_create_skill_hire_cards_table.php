<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kanban de Contratação/Onboarding: quando um candidato é contratado, sobe pra
 * este quadro (classificação vira ERPSERV) e ao concluir o checklist o usuário
 * dele é criado no Minutor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_hire_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('respondent_id')->constrained('skill_respondents')->cascadeOnDelete();
            $table->string('bucket', 30)->default('aguardando_assinatura')
                ->comment('aguardando_assinatura | em_andamento | finalizado | pausado');
            $table->string('title', 160);
            $table->string('priority', 15)->default('media')->comment('baixa | media | alta | urgente');
            $table->json('checklist')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('bucket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_hire_cards');
    }
};
