<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo híbrido da Análise de Qualidade (CodeAnalysis) com a Central de Fontes.
 *
 * O Minutor guarda APENAS o vínculo/negócio (qual fonte, qual versão/blob, quem pediu, status,
 * score resumido, referência ao job externo). O relatório detalhado (findings) permanece no
 * serviço CodeAnalysis — NÃO é copiado para cá. Segue a convenção da família source_docs:
 * migration anônima, hasTable guard, foreignId()->constrained(), SHA como string(64), SEM company_id.
 *
 * Regra de produto: "qualidade pertence a uma VERSÃO específica do fonte" — por isso o vínculo
 * carrega source_doc_version_id + source_blob_sha (identidade da versão analisada).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_doc_quality_analyses')) {
            return;
        }

        Schema::create('source_doc_quality_analyses', function (Blueprint $table) {
            $table->id();

            // Vínculo com a fonte e a VERSÃO analisada (autoridade do Minutor).
            $table->foreignId('source_doc_id')->constrained('source_docs')->cascadeOnDelete();
            $table->foreignId('source_doc_version_id')->nullable()
                ->constrained('source_doc_versions')->nullOnDelete();
            $table->string('source_blob_sha', 64)->nullable(); // git blob sha da versão analisada

            // Referência ao job técnico no CodeAnalysis (autoridade técnica lá).
            $table->string('external_job_id')->nullable();

            // Estado do ciclo de vida (espelha o A1 + 'queued' local antes do job remoto existir).
            $table->string('status', 20)->default('queued'); // queued|running|completed|failed

            // Score RESUMIDO (não são os findings — só o suficiente p/ badge/lista sem round-trip).
            $table->integer('score')->nullable();
            $table->string('grade', 4)->nullable();          // A..F (classificação, se o motor produzir)
            $table->string('risk', 16)->nullable();          // LIMPO/BAIXO/MEDIO/ALTO
            $table->integer('n_critical')->nullable();
            $table->integer('n_warnings')->nullable();
            $table->integer('n_recommendations')->nullable();
            $table->integer('n_findings')->nullable();

            // Motor/versões (p/ cache/reuse e rastreabilidade).
            $table->string('engine')->nullable();            // nome/imagem do analyzer
            $table->string('engine_version')->nullable();    // tag/digest da imagem
            $table->string('rules_version')->nullable();

            // Quem/quando (auditoria de negócio no Minutor).
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Falha (resumida — sem stack/segredo).
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['source_doc_id', 'status']);
            $table->index('source_blob_sha');
            $table->index('external_job_id');
        });

        // Anti-duplo-clique / concorrência: no máximo 1 análise em andamento por (fonte, blob).
        // Índice único PARCIAL — pg e sqlite suportam WHERE em índice. Não usa CONCURRENTLY.
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS sdqa_inflight_uq
             ON source_doc_quality_analyses (source_doc_id, source_blob_sha)
             WHERE status IN ('queued','running')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_quality_analyses');
    }
};
