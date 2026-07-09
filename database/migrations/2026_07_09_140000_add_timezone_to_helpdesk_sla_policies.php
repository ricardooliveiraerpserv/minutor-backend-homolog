<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuso da política de SLA (para o cálculo de horas úteis — janelas em horário LOCAL).
 * Default America/Sao_Paulo (UTC-03), conforme o contrato Cosan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('helpdesk_sla_policies', 'timezone')) {
            Schema::table('helpdesk_sla_policies', function (Blueprint $table) {
                $table->string('timezone', 40)->default('America/Sao_Paulo')->after('business_hours');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('helpdesk_sla_policies', 'timezone')) {
            Schema::table('helpdesk_sla_policies', function (Blueprint $table) {
                $table->dropColumn('timezone');
            });
        }
    }
};
