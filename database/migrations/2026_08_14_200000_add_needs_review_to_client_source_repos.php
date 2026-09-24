<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca vínculo "pendente de verificação": quando o provisionamento automático reaproveita
 * um repositório que JÁ EXISTIA na org (não foi criado por nós), o admin precisa confirmar
 * que é o repo certo do cliente antes de a GMUD commitar nele.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('client_source_repos', 'needs_review')) {
            Schema::table('client_source_repos', function (Blueprint $table) {
                $table->boolean('needs_review')->default(false)->after('active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('client_source_repos', 'needs_review')) {
            Schema::table('client_source_repos', function (Blueprint $table) {
                $table->dropColumn('needs_review');
            });
        }
    }
};
