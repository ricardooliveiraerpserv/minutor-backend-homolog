<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache do último inventário RPO por ambiente — para a Visão Geral mostrar a saúde sem
 * re-rodar o scan pesado (clone + RPO). Guarda só o resumo (counts/health), nunca a lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prosight_rpo_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('prosight_rpo_configs', 'last_scan_summary')) {
                $table->json('last_scan_summary')->nullable();
            }
            if (! Schema::hasColumn('prosight_rpo_configs', 'last_scan_at')) {
                $table->timestamp('last_scan_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('prosight_rpo_configs', function (Blueprint $table) {
            $table->dropColumn(['last_scan_summary', 'last_scan_at']);
        });
    }
};
