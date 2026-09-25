<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessão de Trabalho — conceito de PLATAFORMA (não acoplado ao Help Desk).
 *
 * `scope` distingue o tipo (help_desk agora; comercial/aprovacao/financeira no futuro).
 * A VIVÊNCIA da sessão (fila atual/posição/timers) é client-side; aqui guardamos a sessão
 * e seus EVENTOS de forma DURÁVEL — base para métricas e recomendações de IA no futuro.
 * Eventos: ticket_opened, ticket_finalized, ticket_skipped, playbook_executed, paused, resumed, ended.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('work_sessions')) {
            Schema::create('work_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('scope', 30)->default('help_desk');
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('started_at');
                $table->timestamp('ended_at')->nullable();
                $table->json('context')->nullable(); // filtros/ordenação/fila/label/tamanho inicial
                $table->timestamps();
                $table->index(['user_id', 'scope', 'ended_at']);
            });
        }
        if (!Schema::hasTable('work_session_events')) {
            Schema::create('work_session_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_session_id')->constrained('work_sessions')->cascadeOnDelete();
                $table->string('type', 40);
                $table->string('entity_type', 40)->nullable();  // ex.: helpdesk_ticket
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['work_session_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_session_events');
        Schema::dropIfExists('work_sessions');
    }
};
