<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Fontes — "Trecho alterado da última modificação" (GMUD Fase 2).
 * Guarda o DIFF DE CÓDIGO (unificado) entre esta versão e a anterior do mesmo fonte,
 * para documentar o trecho alterado (detalhe da fonte + MD/PDF + card GMUD).
 * Aditiva + idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_doc_versions', function (Blueprint $table) {
            if (! Schema::hasColumn('source_doc_versions', 'change_diff')) {
                $table->longText('change_diff')->nullable()->after('diff_stats'); // diff unificado (texto)
            }
            if (! Schema::hasColumn('source_doc_versions', 'change_diff_meta')) {
                $table->json('change_diff_meta')->nullable()->after('change_diff'); // {added,removed,is_new,truncated}
            }
        });
    }

    public function down(): void
    {
        Schema::table('source_doc_versions', function (Blueprint $table) {
            foreach (['change_diff_meta', 'change_diff'] as $c) {
                if (Schema::hasColumn('source_doc_versions', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
