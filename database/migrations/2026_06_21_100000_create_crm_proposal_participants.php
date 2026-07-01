<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-B — Participantes da Proposta. A participação é CONTEXTUAL à negociação (vinculada à PROPOSTA,
 * não à oportunidade/cliente/projeto). Múltiplos papéis por participante; múltiplos approvers/signers.
 *
 * Regras de agregação:
 *  - aprovada: TODOS os participantes com papel 'approver' aprovaram (se houver approver).
 *  - assinada: TODOS os participantes com papel 'signer' assinaram (se houver signer).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_proposal_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_proposal_id')->constrained('crm_proposals')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->json('roles');                       // ['viewer','reviewer','approver','signer']
            $table->string('participant_token', 64)->unique(); // acesso/atribuição no portal (link ?pt=)
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable(); // convite aceito (1º acesso)
            $table->timestamp('viewed_at')->nullable();   // 1ª visualização
            $table->timestamp('approved_at')->nullable(); // aprovou (Approver)
            $table->timestamp('signed_at')->nullable();   // assinou (Signer)
            $table->timestamp('last_access_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['crm_proposal_id', 'is_active']);
            $table->index(['crm_proposal_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_proposal_participants');
    }
};
