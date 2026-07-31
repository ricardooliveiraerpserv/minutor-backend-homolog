<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('hour_contributions', 'nao_valorizado')) {
            Schema::table('hour_contributions', function (Blueprint $table) {
                // Aporte "não valorizado": só adiciona horas (apontáveis/vendidas), SEM
                // valor (hourly_rate null), SEM card no kanban e FORA dos cálculos de
                // valor/média ponderada/rentabilidade. Default false = comportamento atual.
                $table->boolean('nao_valorizado')->default(false)->after('hourly_rate');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hour_contributions', 'nao_valorizado')) {
            Schema::table('hour_contributions', function (Blueprint $table) {
                $table->dropColumn('nao_valorizado');
            });
        }
    }
};
