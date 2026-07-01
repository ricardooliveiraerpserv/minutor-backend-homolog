<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Descrição da Oportunidade" — o que o cliente pretende adquirir (ex.: Implantação SmartView,
 * Banco de Horas adicional, Upgrade de Release). Opcional na criação, obrigatória antes da proposta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_opportunities', 'descricao')) {
                $table->text('descricao')->nullable()->after('title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            if (Schema::hasColumn('crm_opportunities', 'descricao')) {
                $table->dropColumn('descricao');
            }
        });
    }
};
