<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplia as Políticas de Comissão: condição por tipo de negócio (opp.tipo:
 * novo_cliente/renovacao) e faixa de atingimento de meta (comissão progressiva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_commission_policies', function (Blueprint $t) {
            if (!Schema::hasColumn('crm_commission_policies', 'tipo_negocio')) $t->string('tipo_negocio', 40)->nullable()->after('pipeline_id');
            if (!Schema::hasColumn('crm_commission_policies', 'min_atingimento')) $t->decimal('min_atingimento', 6, 2)->nullable()->after('max_margem');
            if (!Schema::hasColumn('crm_commission_policies', 'max_atingimento')) $t->decimal('max_atingimento', 6, 2)->nullable()->after('min_atingimento');
        });
    }

    public function down(): void
    {
        Schema::table('crm_commission_policies', function (Blueprint $t) {
            $t->dropColumn(['tipo_negocio', 'min_atingimento', 'max_atingimento']);
        });
    }
};
