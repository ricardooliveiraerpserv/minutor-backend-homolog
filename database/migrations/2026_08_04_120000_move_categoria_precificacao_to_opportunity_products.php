<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categoria e Precificação deixam de ser do PRODUTO e passam a ser da OPORTUNIDADE
 * (por produto vinculado). O produto ganha "origem" (Próprio × Parceiro).
 * Colunas antigas em crm_products são preservadas (dados legados + fallback do dashboard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_opportunity_products', function (Blueprint $t) {
            if (!Schema::hasColumn('crm_opportunity_products', 'categoria')) $t->string('categoria')->nullable();
            if (!Schema::hasColumn('crm_opportunity_products', 'tipo_precificacao')) $t->string('tipo_precificacao')->nullable();
        });
        Schema::table('crm_products', function (Blueprint $t) {
            if (!Schema::hasColumn('crm_products', 'origem')) $t->string('origem')->nullable()->default('proprio');
        });
    }

    public function down(): void
    {
        Schema::table('crm_opportunity_products', function (Blueprint $t) {
            if (Schema::hasColumn('crm_opportunity_products', 'categoria')) $t->dropColumn('categoria');
            if (Schema::hasColumn('crm_opportunity_products', 'tipo_precificacao')) $t->dropColumn('tipo_precificacao');
        });
        Schema::table('crm_products', function (Blueprint $t) {
            if (Schema::hasColumn('crm_products', 'origem')) $t->dropColumn('origem');
        });
    }
};
