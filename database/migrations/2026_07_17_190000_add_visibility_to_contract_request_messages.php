<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duas abas de comunicação na Requisição (Demandas e Projetos):
 *  - visibility='client'   → aba "Comentários" (canal do cliente; cliente lê/escreve)
 *  - visibility='internal' → aba "Diário" (equipe; cliente NÃO vê)
 *
 * Default 'client' preserva o comportamento atual (as conversas existentes
 * eram o canal do cliente e devem continuar aparecendo em Comentários).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contract_request_messages', 'visibility')) {
            Schema::table('contract_request_messages', function (Blueprint $table) {
                $table->string('visibility', 20)->default('client')->after('message');
                $table->index(['contract_request_id', 'visibility'], 'crm_req_visibility_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contract_request_messages', 'visibility')) {
            Schema::table('contract_request_messages', function (Blueprint $table) {
                $table->dropIndex('crm_req_visibility_index');
                $table->dropColumn('visibility');
            });
        }
    }
};
