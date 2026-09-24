<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0 — Histórico técnico imutável: uma source_doc_version por (fonte × commit do código).
 * source_commit_sha = referência do CÓDIGO analisado. documentation_commit_sha (nullable) =
 * commit da DOC quando exportada ao Git — NUNCA substitui o source_sha. Concluída = imutável
 * (garantido no model). Migra as linhas do antigo source_doc_changes e aposenta a tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('source_doc_versions')) {
            Schema::create('source_doc_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('source_doc_id')->constrained('source_docs')->cascadeOnDelete();
                $table->string('source_commit_sha', 64)->nullable();          // SHA do CÓDIGO analisado
                $table->string('parent_source_commit_sha', 64)->nullable();   // base do diff
                $table->string('documentation_commit_sha', 64)->nullable();   // opcional, se exportar a doc ao Git
                $table->unsignedBigInteger('gmud_id')->nullable();            // ticket da GMUD de origem
                $table->string('ticket_number')->nullable();
                $table->unsignedBigInteger('responsible_user_id')->nullable();
                $table->string('responsavel')->nullable();                    // snapshot denormalizado (história sobrevive a exclusão/rename do user)
                $table->json('deterministic_json')->nullable();               // Camada 1
                $table->json('semantic_json')->nullable();                    // Camada 2
                $table->json('documentation_json')->nullable();               // consolidado renderizável
                $table->text('diff_summary')->nullable();                     // resumo técnico da alteração
                $table->json('diff_stats')->nullable();                       // +/- linhas, funções tocadas
                $table->string('analysis_status', 20)->default('pending');    // pending|extracting|analyzing|completed|partial|failed
                $table->unsignedSmallInteger('schema_version')->default(1);
                $table->timestamps();
                $table->unique(['source_doc_id', 'source_commit_sha'], 'source_doc_versions_doc_sha_uq');
                $table->index('gmud_id');
            });
        }

        // FK do ponteiro (source_docs.current_version_id → source_doc_versions.id), sem cascade.
        if (Schema::hasColumn('source_docs', 'current_version_id')) {
            try {
                Schema::table('source_docs', function (Blueprint $table) {
                    $table->foreign('current_version_id', 'source_docs_current_version_fk')
                        ->references('id')->on('source_doc_versions')->nullOnDelete();
                });
            } catch (\Throwable $e) {
                // FK já existe
            }
        }

        // Migra o histórico antigo (source_doc_changes) → versions e ajusta o ponteiro vigente.
        if (Schema::hasTable('source_doc_changes')) {
            foreach (DB::table('source_doc_changes')->orderBy('id')->get() as $c) {
                $vid = DB::table('source_doc_versions')->insertGetId([
                    'source_doc_id'    => $c->source_doc_id,
                    'source_commit_sha' => null,               // o modelo antigo não guardava SHA
                    'gmud_id'          => $c->ticket_id ?? null,
                    'ticket_number'    => $c->ticket_number ?? null,
                    'responsavel'      => $c->responsavel ?? null,
                    'diff_summary'     => $c->resumo ?? null,
                    'analysis_status'  => 'completed',
                    'schema_version'   => 1,
                    'created_at'       => $c->created_at ?? now(),
                    'updated_at'       => $c->updated_at ?? now(),
                ]);
                // Ponteiro da versão vigente = a última migrada por fonte.
                DB::table('source_docs')->where('id', $c->source_doc_id)->update(['current_version_id' => $vid]);
            }
            Schema::dropIfExists('source_doc_changes');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('source_docs', 'current_version_id')) {
            try {
                Schema::table('source_docs', function (Blueprint $table) {
                    $table->dropForeign('source_docs_current_version_fk');
                });
            } catch (\Throwable $e) {}
        }
        Schema::dropIfExists('source_doc_versions');
        // (não recria source_doc_changes — rollback deixa o histórico apenas em versions)
    }
};
