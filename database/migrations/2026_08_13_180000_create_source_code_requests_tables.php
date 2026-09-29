<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — Solicitação de código-fonte (Help Desk). Cabeçalho + itens (1 por fonte).
 * Cada item vira um anexo (FASE 11) numa interação do chamado. Reservas nullable p/ a
 * Fase 2 (devolução/GMUD). READ-ONLY no GitHub — nada aqui escreve no repositório.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('source_code_requests')) {
            Schema::create('source_code_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->nullable()->constrained('helpdesk_tickets')->nullOnDelete();
                $table->foreignId('client_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('comment_id')->nullable()->constrained('helpdesk_ticket_comments')->nullOnDelete(); // a interação "Códigos-fonte anexados"
                $table->string('status', 20)->default('processing'); // processing | done | partial
                $table->timestamps();
                $table->index('ticket_id');
                $table->index('client_id');
            });
        }

        if (! Schema::hasTable('source_code_request_items')) {
            Schema::create('source_code_request_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('request_id')->constrained('source_code_requests')->cascadeOnDelete();
                $table->string('owner', 120);
                $table->string('repository', 140);
                $table->string('branch', 140);
                $table->string('path', 500);
                $table->string('filename', 255);
                $table->string('original_commit_sha', 64)->nullable();
                $table->timestamp('original_commit_at')->nullable();
                $table->string('commit_author', 200)->nullable();
                $table->text('commit_message')->nullable();
                $table->foreignId('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
                $table->string('status', 20)->default('pending'); // pending | attached | failed
                $table->text('error')->nullable();
                // Reservas Fase 2 (devolução / GMUD / PR) — nullable, sem uso agora.
                $table->foreignId('returned_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
                $table->unsignedBigInteger('gmud_id')->nullable();
                $table->string('new_commit_sha', 64)->nullable();
                $table->string('pull_request_url', 400)->nullable();
                $table->string('return_status', 30)->nullable();
                $table->timestamps();
                $table->index('request_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('source_code_request_items');
        Schema::dropIfExists('source_code_requests');
    }
};
