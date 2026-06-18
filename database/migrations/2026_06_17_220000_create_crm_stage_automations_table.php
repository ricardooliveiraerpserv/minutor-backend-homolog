<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM configurável — Fase 3. Motor de automações por etapa (estilo n8n interno).
 * Ao ENTRAR numa etapa, executa as automações ativas em ordem. Extensível: novo tipo
 * = novo handler no StageAutomationRunner, sem migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_stage_automations')) return;
        Schema::create('crm_stage_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('crm_pipeline_stages')->cascadeOnDelete();
            $table->string('evento', 30)->default('ao_entrar');   // gatilho (extensível: ao_sair, etc.)
            $table->string('tipo', 40);                            // criar_tarefa | alterar_status_empresa | enviar_email | notificar | gerar_proposta | gerar_contrato | webhook
            $table->json('config')->nullable();
            $table->integer('ordem')->default(0);
            $table->boolean('ativa')->default(true);
            $table->timestamps();
            $table->index(['stage_id', 'evento', 'ativa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_stage_automations');
    }
};
