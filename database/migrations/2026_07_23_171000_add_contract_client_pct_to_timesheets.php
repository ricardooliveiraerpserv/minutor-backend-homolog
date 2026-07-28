<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * % de uplift do cliente DERIVADO da regra do contrato (contract_hour_multipliers),
 * denormalizado no timesheet pra a cobrança do lado cliente ler sem join recursivo.
 *
 * Efetivo na cobrança = COALESCE(contract_client_pct, client_extra_pct, 0):
 *   - contract_client_pct preenchido (há regra vigente) → a regra do contrato VENCE (decisão B);
 *   - nulo (sem regra) → cai no client_extra_pct manual (comportamento atual).
 *
 * Mantido por ContractHourMultiplierService::recompute* (ao salvar timesheet e ao
 * criar/editar/remover regra). NUNCA lido pelo lado consultor/parceiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheets', 'contract_client_pct')) {
                $table->decimal('contract_client_pct', 8, 2)->nullable()->after('client_extra_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (Schema::hasColumn('timesheets', 'contract_client_pct')) {
                $table->dropColumn('contract_client_pct');
            }
        });
    }
};
