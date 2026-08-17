<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-source Fase 1 — resolução DETERMINÍSTICA (read-only, sem IA). Duas tabelas ADITIVAS
 * (read-model derivado + grafo de proveniência); não alteram o determinístico nem a semântica.
 *
 *  source_symbol_definition — índice "quem define o símbolo X" (por repo/blob), reconstruível.
 *  source_semantic_context_edge — grafo dependente→alvo (proveniência transitiva + invalidação GMUD).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Índice de definições — read-model derivado do determinístico (descartável/reconstruível).
        Schema::create('source_symbol_definition', function (Blueprint $t) {
            $t->id();
            $t->string('symbol_norm', 191);            // nome normalizado (sem U_, lower)
            $t->unsignedBigInteger('source_doc_id');
            $t->unsignedBigInteger('version_id')->nullable();
            $t->string('blob_sha', 64)->nullable();     // p/ dedup de arquivos duplicados
            $t->string('owner', 191)->nullable();
            $t->string('repository', 191)->nullable();
            $t->string('function_name', 191);           // nome original (com caixa)
            $t->integer('start_line')->nullable();
            $t->integer('end_line')->nullable();
            $t->boolean('is_user_function')->default(false); // só User Function é chamável cross-file (AdvPL)
            $t->boolean('writes')->default(false);      // escreve em tabela? (sinal de "negócio" p/ relevância)
            $t->integer('touches_tables')->default(0);
            $t->timestamps();
            $t->index(['repository', 'symbol_norm']);
            $t->index('symbol_norm');
            $t->index('source_doc_id');
            $t->index('blob_sha');
        });

        // Grafo de proveniência/dependência cross-source (dependente → alvo).
        Schema::create('source_semantic_context_edge', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dependent_source_doc_id');
            $t->unsignedBigInteger('target_source_doc_id')->nullable(); // null quando unresolved
            $t->string('symbol', 191);
            $t->string('relation', 40)->default('calls_user');
            $t->string('state', 20);                    // resolved | ambiguous | unresolved
            $t->string('evidence_level', 2)->nullable(); // A..E (C = cross-source resolvido)
            $t->string('target_blob_sha', 64)->nullable();
            $t->decimal('relevance_score', 5, 3)->nullable();
            $t->boolean('included_in_context')->default(false); // entrou no bounded context (futuro)?
            $t->string('reason', 60)->nullable();        // motivo (incluído / descartado_*)
            $t->integer('candidates_count')->default(0); // nº de definidores antes do dedup
            $t->integer('candidates_after_dedup')->default(0);
            $t->integer('est_context_tokens')->nullable();
            $t->timestamps();
            $t->index(['dependent_source_doc_id']);
            $t->index(['target_source_doc_id']);        // p/ invalidação GMUD (quem depende de B)
            $t->index(['state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_semantic_context_edge');
        Schema::dropIfExists('source_symbol_definition');
    }
};
