<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot semanal do forecast — permite analisar crescimento, queda e slippage ao longo do tempo.
 * Cada linha congela os números do forecast de uma competência num instante.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('crm_forecast_snapshots')) return;
        Schema::create('crm_forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('data');              // data do snapshot
            $table->string('periodo', 7);      // competência YYYY-MM a que se refere
            $table->decimal('meta', 14, 2)->default(0);
            $table->decimal('previsto', 14, 2)->default(0);
            $table->decimal('commit', 14, 2)->default(0);
            $table->decimal('best_case', 14, 2)->default(0);
            $table->decimal('pipeline', 14, 2)->default(0);
            $table->decimal('fechado', 14, 2)->default(0);
            $table->decimal('coverage', 8, 2)->nullable();
            $table->timestamps();
            $table->index(['periodo', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_forecast_snapshots');
    }
};
