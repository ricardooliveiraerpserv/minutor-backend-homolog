<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloco 4.2.1-A — REUSO SEMÂNTICO PERSISTENTE POR BLOB (read-model DERIVADO, descartável).
 *
 * Reusa a ANÁLISE (semantic_json), NUNCA o documento: cada source_doc/version mantém seu próprio
 * cliente/repo/path/GMUD/status Git/histórico. Aqui guardamos só o resultado semântico por
 * CONTEÚDO IDÊNTICO + CONTRATO, para não repagar IA nas 670 réplicas do acervo.
 *
 * Chave de compatibilidade (UNIQUE): blob_sha + facts_version + schema_version + prompt_version + model.
 *  - facts_version: versão do contrato DETERMINÍSTICO/de fatos. Se o parser evoluir e extrair novos
 *    fatos do mesmo código (mesmo blob), bumpar facts_version invalida a semântica antiga — não
 *    congela uma interpretação velha para sempre.
 *  - schema/prompt/model: contrato semântico. Resultado incompatível NUNCA é reutilizado.
 *
 * NÃO é fonte da verdade: o semantic_json vivo continua sendo o persistido em source_doc_versions.
 * Esta tabela é reconstruível e pode ser truncada sem perda (só re-paga IA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_semantic_blob_cache', function (Blueprint $table) {
            $table->id();
            $table->string('blob_sha', 64);
            $table->unsignedInteger('facts_version');
            $table->unsignedInteger('schema_version');
            $table->unsignedInteger('prompt_version');
            $table->string('model', 80);
            $table->jsonb('semantic_json');
            // Proveniência/auditoria do reuso (NUNCA compartilha cliente/repo/path — só rastreia origem).
            $table->unsignedBigInteger('first_source_doc_id')->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->unique(['blob_sha', 'facts_version', 'schema_version', 'prompt_version', 'model'], 'ssbc_contract_uq');
            $table->index('blob_sha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_semantic_blob_cache');
    }
};
