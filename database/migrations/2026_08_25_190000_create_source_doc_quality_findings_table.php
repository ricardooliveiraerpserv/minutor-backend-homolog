<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 — Findings duráveis da Análise de Qualidade, no Postgres (autoridade histórica no Minutor).
 *
 * ANTES: os achados viviam SÓ no store efêmero (SQLite) do CodeAnalysis, buscados ao vivo por
 * external_job_id. Um restart/deploy do CA (filesystem efêmero, sem disco) apagava os achados
 * mesmo de análises `completed`. Agora o BFF persiste os achados aqui quando a análise conclui;
 * o CodeAnalysis vira MOTOR DESCARTÁVEL (pode morrer/voltar sem levar a memória histórica).
 *
 * SEGURANÇA: NÃO guardamos snippet/código-fonte bruto — só METADADOS do achado. O trecho de código
 * (quando o usuário tem source_docs.view_git) é obtido sob demanda a partir da versão/blob, nunca
 * gravado no banco. Segue a convenção da família source_docs (migration anônima, hasTable guard,
 * foreignId()->constrained(), SEM company_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_doc_quality_findings')) {
            return;
        }

        Schema::create('source_doc_quality_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_doc_quality_analysis_id')
                ->constrained('source_doc_quality_analyses')->cascadeOnDelete();

            $table->integer('position')->default(0); // ordem estável do relatório (para render idêntico)

            // Metadados do achado (NUNCA código-fonte).
            $table->string('rule')->nullable();              // rule/code do analyzer
            $table->string('severity', 16)->nullable();      // normalizada: BLOCKER/CRITICAL/MAJOR/MINOR/INFO
            $table->string('analyzer_severity', 32)->nullable(); // severidade crua do motor
            $table->string('category')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('file')->nullable();              // caminho do arquivo (não é código)
            $table->integer('line')->nullable();
            $table->integer('start_line')->nullable();
            $table->integer('col')->nullable();              // coluna, se o motor fornecer (hoje não fornece)
            $table->integer('occurrences')->nullable();      // 'count' do analyzer
            $table->json('meta')->nullable();                // extras NÃO sensíveis (sem snippet/código)

            $table->timestamps();

            $table->index('source_doc_quality_analysis_id');
            $table->index(['source_doc_quality_analysis_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_quality_findings');
    }
};
