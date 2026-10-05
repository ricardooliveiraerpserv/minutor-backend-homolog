<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache da LISTA do último inventário RPO (campos de exibição) para o drill-down na Visão Geral
 * (clicar num card → lista filtrada abaixo) sem re-rodar o scan pesado. Separado do resumo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prosight_rpo_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('prosight_rpo_configs', 'last_scan_results')) {
                $table->json('last_scan_results')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('prosight_rpo_configs', function (Blueprint $table) {
            $table->dropColumn('last_scan_results');
        });
    }
};
