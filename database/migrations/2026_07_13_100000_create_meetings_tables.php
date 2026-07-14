<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Reuniões — Fase 0 (fundação do domínio, sem provider externo ainda).
 * A reunião é uma entidade polimórfica: origin_type/origin_id apontam para a origem
 * (HELPDESK_TICKET | PROJECT | CUSTOMER | CONTRACT | AGENDA). O Teams entra depois como adapter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meetings')) {
            Schema::create('meetings', function (Blueprint $table) {
                $table->id();
                $table->string('title', 200);
                $table->text('description')->nullable();
                // teams | meet | zoom | webex | presencial
                $table->string('provider', 20)->default('presencial');
                // scheduled | live | ended | canceled
                $table->string('status', 16)->default('scheduled');
                $table->timestampTz('starts_at');
                $table->timestampTz('ends_at')->nullable();
                $table->integer('duration_minutes')->nullable();
                $table->string('timezone', 40)->default('America/Sao_Paulo');
                $table->unsignedBigInteger('organizer_user_id')->nullable();
                // Origem polimórfica (registry resolve o model). Sem FK — vários alvos.
                $table->string('origin_type', 40)->nullable();
                $table->unsignedBigInteger('origin_id')->nullable();
                // Preenchidos pelo provider (Teams etc.).
                $table->string('external_meeting_id', 255)->nullable();
                $table->text('join_url')->nullable();
                $table->json('provider_data')->nullable();
                // Marcos reais (início/fim efetivos) — distintos de starts_at/ends_at (planejado).
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('ended_at')->nullable();
                // Pós-reunião (estrutura pronta p/ IA/gravação — preenchidos nas fases seguintes).
                $table->text('summary')->nullable();
                $table->text('ata')->nullable();
                $table->text('recording_url')->nullable();
                $table->text('transcript_url')->nullable();
                $table->unsignedBigInteger('created_by_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['origin_type', 'origin_id']);
                $table->index('starts_at');
                $table->index('status');
            });
        }

        if (!Schema::hasTable('meeting_participants')) {
            Schema::create('meeting_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable();           // interno
                $table->unsignedBigInteger('customer_contact_id')->nullable(); // contato do cliente
                $table->string('email', 190)->nullable();
                $table->string('name', 190)->nullable();
                // organizer | solicitante | responsavel | consultor | coordenador | required | optional
                $table->string('role', 20)->default('required');
                // none | accepted | declined | tentative
                $table->string('response', 12)->default('none');
                $table->boolean('is_external')->default(false);
                $table->timestamps();
                $table->index('meeting_id');
            });
        }

        if (!Schema::hasTable('meeting_events')) {
            Schema::create('meeting_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
                // scheduled | invites_sent | started | ended | rescheduled | canceled |
                // summary_ready | ata_generated | tasks_created | hours_logged
                $table->string('event_type', 40);
                $table->json('meta')->nullable();
                $table->unsignedBigInteger('triggered_by')->nullable();
                $table->timestampTz('created_at')->nullable();
                $table->index('meeting_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_events');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};
