<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telemetria de USO (Fase de Consolidação do Help Desk).
 *
 * Fluxo append-only de eventos de PRODUTO — distinto da timeline do chamado
 * (helpdesk_ticket_events, história de domínio) e da vivência da sessão
 * (work_session_events). Objetivo: medir se as funcionalidades são USADAS, não só se
 * funcionam. É a base de EVIDÊNCIAS sobre a qual a futura Central Inteligente (IA) nasce.
 *
 * Genérico por desenho (coluna `scope`, default 'help_desk') para reuso na plataforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 30)->default('help_desk');
            $table->string('feature', 40);                 // ticket | finalize | playbook | customer_360 | redistribute ...
            $table->string('action', 40);                  // used | executed | viewed | block_toggled | resolved ...
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('entity_type', 40)->nullable(); // helpdesk_ticket | customer | playbook
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('work_session_id')->nullable(); // liga ao Modo Atendimento quando aplicável
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();   // imutável; sem updated_at (log puro)

            $table->index(['scope', 'feature', 'action', 'created_at'], 'usage_events_feature_idx');
            $table->index(['entity_type', 'entity_id'], 'usage_events_entity_idx');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
