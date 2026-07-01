<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal de Propostas (Fase 1) — compartilhamento tokenizado de uma proposta.
 *
 * A proposta continua sendo CrmProposal (negócio) + Document (PDF) + DocumentEvent (auditoria).
 * Esta tabela é APENAS a camada de ACESSO público: o token (não adivinhável) que abre o portal,
 * com validade/revogação. Os EVENTOS de visualização/aceite são a verdade em DocumentEvent;
 * as colunas first/last_viewed_at + view_count são CACHE derivado (índice rápido p/ os painéis),
 * atualizadas junto do evento — não substituem a auditoria.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_proposal_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('crm_proposals')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->string('destinatario', 255)->nullable();  // e-mail/nome do destinatário (dica)
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            // Cache de engajamento (verdade granular vive em document_events):
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('read_seconds')->default(0); // tempo acumulado de leitura (heartbeat)
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamp('expired_marked_at')->nullable(); // idempotência da marcação de expiração
            $table->timestamps();

            $table->index(['proposal_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_proposal_shares');
    }
};
