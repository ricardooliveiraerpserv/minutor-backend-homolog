<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliação de job perdido (anti-`job_lost` prematuro).
 *
 * Um único 404 de GET job pode ser TRANSITÓRIO (restart/eviction/timeout do CodeAnalysis). Para não
 * terminar a análise como `job_lost` na primeira ausência, guardamos um marcador leve: quando o CA
 * está SAUDÁVEL e o job some (404), registra-se `missing_since`. Só uma 2ª ausência confirmada numa
 * consulta posterior (com o CA ainda saudável e passada uma pequena tolerância) vira `job_lost`.
 * Sem sleep bloqueante no request; usa o polling já existente do frontend. Sem Redis/fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('source_doc_quality_analyses')
            || Schema::hasColumn('source_doc_quality_analyses', 'missing_since')) {
            return;
        }
        Schema::table('source_doc_quality_analyses', function (Blueprint $table) {
            // Timestamp da 1ª ausência (404) observada com o CA saudável. Null = sem ausência pendente.
            $table->timestamp('missing_since')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('source_doc_quality_analyses')
            && Schema::hasColumn('source_doc_quality_analyses', 'missing_since')) {
            Schema::table('source_doc_quality_analyses', function (Blueprint $table) {
                $table->dropColumn('missing_since');
            });
        }
    }
};
