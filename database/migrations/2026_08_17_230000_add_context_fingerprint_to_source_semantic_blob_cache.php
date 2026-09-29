<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 (cross-source) — P0 do fingerprint de contexto.
 *
 * A chave de reuso semântico por blob NÃO pode mais ser só (blob + facts/schema/prompt/model): ao
 * alimentar contexto EXTERNO no prompt, a mesma versão principal (blob A) pode ser analisada com
 * contexto B ou contexto C e produzir semânticas DIFERENTES. Sem o fingerprint na chave, o cache
 * serviria silenciosamente a documentação de contexto B para uma ocorrência cujo contexto efetivo é C —
 * erro invisível, mais perigoso que retornar partial.
 *
 * context_fingerprint = hash determinístico e ORDENADO dos contextos efetivamente usados.
 *   - self-contained → '' (neutro): linhas existentes recebem '' → reuso self-contained INTACTO.
 *   - cross-source   → hash não-vazio → nunca colide com a semântica sem contexto ou com contexto diferente.
 *
 * Aditiva e reversível (a tabela é read-model derivado/reconstruível).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_semantic_blob_cache', function (Blueprint $table) {
            // '' = neutro (self-contained). Linhas antigas (todas self-contained até aqui) herdam '' e
            // continuam reutilizáveis por análises sem contexto.
            $table->string('context_fingerprint', 64)->default('')->after('model');
        });

        // A chave de compatibilidade passa a INCLUIR o fingerprint. Recria o UNIQUE.
        Schema::table('source_semantic_blob_cache', function (Blueprint $table) {
            $table->dropUnique('ssbc_contract_uq');
        });
        Schema::table('source_semantic_blob_cache', function (Blueprint $table) {
            $table->unique(
                ['blob_sha', 'facts_version', 'schema_version', 'prompt_version', 'model', 'context_fingerprint'],
                'ssbc_contract_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::table('source_semantic_blob_cache', function (Blueprint $table) {
            $table->dropUnique('ssbc_contract_uq');
        });
        Schema::table('source_semantic_blob_cache', function (Blueprint $table) {
            $table->unique(['blob_sha', 'facts_version', 'schema_version', 'prompt_version', 'model'], 'ssbc_contract_uq');
            $table->dropColumn('context_fingerprint');
        });
    }
};
