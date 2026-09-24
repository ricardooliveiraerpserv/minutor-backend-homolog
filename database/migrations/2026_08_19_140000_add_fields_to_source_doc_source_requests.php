<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Central de Fontes — solicitação de fonte ganha: chamado (ticket), prioridade,
// escopo (source/folder/repository) e lista de caminhos (múltiplas fontes ou pasta).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_doc_source_requests', function (Blueprint $t) {
            $t->string('ticket')->nullable()->after('repository');
            $t->string('priority')->default('media')->after('ticket'); // baixa | media | alta
            $t->string('scope_type')->default('repository')->after('priority'); // source | folder | repository
            $t->json('paths')->nullable()->after('scope_type');
        });
    }

    public function down(): void
    {
        Schema::table('source_doc_source_requests', function (Blueprint $t) {
            $t->dropColumn(['ticket', 'priority', 'scope_type', 'paths']);
        });
    }
};
