<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead — Previsão de fechamento p/ forecast comercial. Aceita data exata (previsao_fechamento)
 * OU competência mês/ano (previsao_competencia = 'YYYY-MM'). A exibição prefere a competência.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_crm_profiles', 'previsao_fechamento')) $table->date('previsao_fechamento')->nullable();
            if (!Schema::hasColumn('customer_crm_profiles', 'previsao_competencia')) $table->string('previsao_competencia', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $table) {
            foreach (['previsao_fechamento', 'previsao_competencia'] as $c) if (Schema::hasColumn('customer_crm_profiles', $c)) $table->dropColumn($c);
        });
    }
};
