<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloco 3 — IDENTIDADE TÉCNICA DO ARQUIVO por BLOB SHA (não por commit).
 *
 * `source_blob_sha` = git blob SHA (o "sha" que a Contents/Trees API do GitHub devolve para o
 * ARQUIVO — `git hash-object`). É a identidade imutável do CONTEÚDO daquela versão do fonte.
 * A pergunta "a doc representa o arquivo que está no Git agora?" passa a ser respondida por
 * source_blob_sha documentado × blob_sha atual do MESMO path — NUNCA pelo commit HEAD do branch.
 *
 * Nullable p/ compatibilidade: versões antigas (Fase 3/GMUD anteriores) não têm o SHA e ficarão
 * NAO_VALIDADO (reason=missing_documented_sha). NÃO backfillar/fabricar (ver SourceDocStatusResolver).
 *
 * Sem índice: a leitura é sempre por versão já carregada (doc→currentVersion→source_blob_sha);
 * não há consulta que filtre por source_blob_sha. Aditiva pura, idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_doc_versions') && !Schema::hasColumn('source_doc_versions', 'source_blob_sha')) {
            Schema::table('source_doc_versions', function (Blueprint $table) {
                $table->string('source_blob_sha', 64)->nullable()->after('source_commit_sha');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('source_doc_versions') && Schema::hasColumn('source_doc_versions', 'source_blob_sha')) {
            Schema::table('source_doc_versions', function (Blueprint $table) {
                $table->dropColumn('source_blob_sha');
            });
        }
    }
};
