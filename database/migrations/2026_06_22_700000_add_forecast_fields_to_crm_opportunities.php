<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previsibilidade comercial na oportunidade:
 *  - probabilidade (%) manual (override da probabilidade da etapa) → valor ponderado = valor × prob/100
 *  - motivo_parada: o que impede o avanço quando a oportunidade fica sem interação (gestão do pipeline)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_opportunities', 'probabilidade')) $table->unsignedTinyInteger('probabilidade')->nullable();
            if (!Schema::hasColumn('crm_opportunities', 'motivo_parada')) $table->string('motivo_parada', 40)->nullable();
            if (!Schema::hasColumn('crm_opportunities', 'parada_em')) $table->timestamp('parada_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            foreach (['probabilidade', 'motivo_parada', 'parada_em'] as $c) if (Schema::hasColumn('crm_opportunities', $c)) $table->dropColumn($c);
        });
    }
};
