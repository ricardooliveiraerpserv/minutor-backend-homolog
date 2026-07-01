<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categoria de forecast (Commit/Best Case/Pipeline/Omitido) — substitui a dependência do %
 * por um julgamento explícito de comprometimento do vendedor. Omitido sai do forecast.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_opportunities', 'forecast_categoria')) {
                $table->string('forecast_categoria', 12)->nullable(); // commit | best_case | pipeline | omitido
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            if (Schema::hasColumn('crm_opportunities', 'forecast_categoria')) $table->dropColumn('forecast_categoria');
        });
    }
};
