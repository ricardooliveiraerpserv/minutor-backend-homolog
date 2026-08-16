<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Fontes — C2. Entidades técnicas PESQUISÁVEIS derivadas do deterministic_json:
 * N linhas por fonte. É o núcleo da busca técnica. READ-MODEL descartável/reconstruível.
 *
 * entity_type: function | table | field | query | integration | dependency | risk
 * "quem usa SC2"        → entity_type='table'  AND lower(name)='sc2'
 * "quem escreve CAMPO"  → entity_type='field'  AND lower(name)=... AND access @> '["UPDATE"]'
 * "SQL dinâmico"        → entity_type='risk'   AND name='dynamic_sql_by_concatenation'
 *
 * A busca usa SEMPRE este índice (nunca faz scan do deterministic_json).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_doc_entities')) {
            return;
        }
        Schema::create('source_doc_entities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_doc_id');
            $table->unsignedBigInteger('source_doc_version_id');
            $table->string('entity_type', 20);
            $table->string('name', 300);                 // token pesquisável
            $table->string('parent', 300)->nullable();   // contexto (tabela do campo, função da query)
            $table->jsonb('access')->nullable();          // tabelas: READ/INSERT/UPDATE/DELETE ; query: [operation]
            $table->jsonb('risk_flags')->nullable();
            $table->unsignedInteger('line_start')->nullable();
            $table->unsignedInteger('line_end')->nullable();
            // denormalizado p/ escopo sem join:
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('owner');
            $table->string('repository');

            $table->foreign('source_doc_id')->references('id')->on('source_docs')->cascadeOnDelete();
            $table->index('source_doc_id');
            $table->index('customer_id');
            $table->index(['owner', 'repository']);
        });

        // Exato/prefixo case-insensitive: índice funcional em (entity_type, lower(name)).
        DB::statement('CREATE INDEX source_doc_entities_type_lname ON source_doc_entities (entity_type, lower(name) text_pattern_ops)');
        // Containment em access/risk_flags.
        DB::statement('CREATE INDEX source_doc_entities_access_gin ON source_doc_entities USING gin (access jsonb_path_ops)');
        DB::statement('CREATE INDEX source_doc_entities_risk_gin ON source_doc_entities USING gin (risk_flags jsonb_path_ops)');
        // "Contém" (ilike %x%): trigram, se disponível. Guardado — cai p/ btree se pg_trgm faltar.
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX source_doc_entities_name_trgm ON source_doc_entities USING gin (lower(name) gin_trgm_ops)');
        } catch (\Throwable $e) {
            // pg_trgm indisponível: busca "contém" ainda funciona (seq/btree); exato/prefixo já cobertos.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_entities');
    }
};
