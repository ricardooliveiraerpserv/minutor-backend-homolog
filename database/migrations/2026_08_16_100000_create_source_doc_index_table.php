<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Fontes — C2. READ-MODEL derivado (NÃO é fonte de verdade): 1 linha por fonte,
 * reconstruível a qualquer momento a partir do deterministic_json da versão vigente.
 *
 * Resolve o catálogo (functions_count etc. em O(1), sem ler os ~360KB de JSON por linha) e os
 * filtros. NÃO materializa a situação Git (ATUALIZADA/DESATUALIZADA/NAO_VALIDADO) — essa continua
 * EXCLUSIVA do SourceDocStatusResolver (blob × árvore atual, ao vivo). Só guarda o que é do banco:
 * analysis_status e semantic_quality.
 *
 * Consistência (stale) por indexed_version_id + indexed_blob_sha (ver SourceDocIndexer). indexed_at
 * é só auditoria — NUNCA usado para decidir validade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_doc_index')) {
            return;
        }
        Schema::create('source_doc_index', function (Blueprint $table) {
            $table->unsignedBigInteger('source_doc_id')->primary();
            $table->unsignedBigInteger('indexed_version_id');          // versão vigente indexada
            $table->string('indexed_blob_sha', 64)->nullable();        // blob da versão indexada
            $table->unsignedInteger('functions_count')->default(0);
            $table->unsignedInteger('tables_count')->default(0);
            $table->unsignedInteger('queries_count')->default(0);
            $table->boolean('has_risk')->default(false);
            $table->jsonb('risk_flags')->nullable();                   // distinct risk flags do fonte
            $table->jsonb('integrations')->nullable();                 // distinct integrações
            // denormalizado p/ filtros/escopo sem join:
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('owner');
            $table->string('repository');
            $table->string('branch');
            $table->string('lang', 20)->nullable();
            $table->string('tipo', 40)->nullable();
            $table->string('analysis_status', 20)->nullable();         // do banco (não é situação Git)
            $table->string('semantic_quality', 20)->nullable();        // completed|partial|none
            $table->timestamp('indexed_at')->nullable();               // AUDITORIA apenas

            $table->foreign('source_doc_id')->references('id')->on('source_docs')->cascadeOnDelete();
            $table->index('customer_id');
            $table->index(['owner', 'repository']);
            $table->index('analysis_status');
            $table->index('has_risk');
        });

        // GIN p/ containment em risk_flags/integrations (whereJsonContains).
        DB::statement('CREATE INDEX source_doc_index_risk_gin ON source_doc_index USING gin (risk_flags jsonb_path_ops)');
        DB::statement('CREATE INDEX source_doc_index_integr_gin ON source_doc_index USING gin (integrations jsonb_path_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_index');
    }
};
