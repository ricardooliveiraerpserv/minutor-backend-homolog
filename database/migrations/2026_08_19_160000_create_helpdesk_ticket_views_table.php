<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presença de visualização de chamado (Help Desk): quem está com o chamado ABERTO
 * na tela agora. Heartbeat (last_seen_at) atualizado a cada ~10s pelo FE; "ativo" =
 * visto nos últimos ~30s. Usado no olho de "quem está visualizando" + botão Atualizar.
 * Vale p/ agente (users) e cliente (users com customer_id, via portal).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_ticket_views')) return;

        Schema::create('helpdesk_ticket_views', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('helpdesk_tickets')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('last_seen_at')->useCurrent();
            $t->timestamps();
            $t->unique(['ticket_id', 'user_id']);
            $t->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_ticket_views');
    }
};
