<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-C.2 — Comentários e Revisões POR SEÇÃO (nada global).
 *  - crm_proposal_review_threads: uma discussão sobre uma seção específica (aberta/respondida/resolvida).
 *  - crm_proposal_review_messages: mensagens da thread (múltiplas respostas; append-only = histórico preservado).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('crm_proposal_review_threads')) {
            Schema::create('crm_proposal_review_threads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('crm_proposal_id')->constrained('crm_proposals')->cascadeOnDelete();
                $table->foreignId('crm_proposal_section_id')->constrained('crm_proposal_sections')->cascadeOnDelete();
                $table->string('section_key', 40);                                   // denormalizado p/ resiliência/consulta
                $table->unsignedBigInteger('created_by_participant_id')->nullable(); // null = aberta internamente (comercial)
                $table->string('subject', 200);
                $table->string('status', 16)->default('aberta');                     // aberta | respondida | resolvida
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by_participant_id')->nullable();
                $table->unsignedBigInteger('resolved_by_user_id')->nullable();
                $table->timestamps();
                $table->index(['crm_proposal_id', 'section_key']);
                $table->index(['crm_proposal_id', 'status']);
            });
        }

        if (!Schema::hasTable('crm_proposal_review_messages')) {
            Schema::create('crm_proposal_review_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('crm_proposal_review_thread_id')->constrained('crm_proposal_review_threads')->cascadeOnDelete();
                $table->unsignedBigInteger('crm_proposal_participant_id')->nullable(); // autor cliente
                $table->unsignedBigInteger('author_user_id')->nullable();              // autor interno (comercial)
                $table->text('message');
                $table->timestamps();
                $table->index('crm_proposal_review_thread_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_proposal_review_messages');
        Schema::dropIfExists('crm_proposal_review_threads');
    }
};
