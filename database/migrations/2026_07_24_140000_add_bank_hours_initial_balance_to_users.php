<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saldo inicial do BANCO DE HORAS trazido de outro sistema (ex.: -100h de saldo
     * devedor). Semeia o cálculo no 1º mês (mês de bank_hours_start_date). Negativo
     * = devedor; positivo = credor. Só relevante p/ consultor banco_de_horas.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'bank_hours_initial_balance')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('bank_hours_initial_balance', 10, 2)->default(0)->after('bank_hours_start_date');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'bank_hours_initial_balance')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bank_hours_initial_balance');
        });
    }
};
