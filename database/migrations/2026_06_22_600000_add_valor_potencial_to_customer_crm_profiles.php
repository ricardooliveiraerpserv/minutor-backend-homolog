<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead — Potencial de negócio: valor estimado da OPORTUNIDADE (≠ faturamento da empresa).
 * A faixa (até 5k / 5–20k / +20k) é derivada na exibição.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_crm_profiles', 'valor_potencial')) {
                $table->decimal('valor_potencial', 14, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('customer_crm_profiles', 'valor_potencial')) $table->dropColumn('valor_potencial');
        });
    }
};
