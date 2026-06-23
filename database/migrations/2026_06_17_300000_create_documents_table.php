<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plataforma de Documentos (Fase 0.7) — espinha GENÉRICA do artefato renderizado.
 * NÃO é proposta/contrato — é o documento (PDF congelado de uma versão) de QUALQUER tipo.
 * Doc: docs/plataforma-documentos-arquitetura-final.md
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('documents')) {
            return;
        }
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40)->index();        // proposta|contrato|fechamento|relatorio|...
            $table->string('entity_type', 40)->nullable();        // polimórfico (CrmProposal|Contract|...) — null no teste genérico
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('codigo', 40)->nullable()->index();    // só tipos comerciais (ESP012-26); null nos demais
            $table->unsignedInteger('versao')->default(1);
            $table->string('status', 24)->default('gerado');
            $table->string('template', 80);                       // identificador do template usado
            $table->string('renderer', 16)->default('chromium');  // chromium|dompdf
            $table->string('hash', 64)->nullable()->index();      // cache: sha256 de (type+entity+versao+params)
            $table->json('params_snapshot')->nullable();          // congela os dados da geração
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            // Assinatura digital — apenas PREPARADO (não implementado nesta fase).
            $table->string('status_assinatura', 24)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_type', 'entity_type', 'entity_id', 'versao'], 'documents_type_entity_versao_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
