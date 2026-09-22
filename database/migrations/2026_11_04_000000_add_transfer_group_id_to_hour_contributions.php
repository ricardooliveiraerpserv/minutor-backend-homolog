<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('hour_contributions', 'transfer_group_id')) {
            Schema::table('hour_contributions', function (Blueprint $table) {
                // Vincula o par de aportes (origem −X / destino +X) de uma transferência de horas
                // entre contratos do mesmo cliente. Null = aporte normal.
                $table->string('transfer_group_id', 40)->nullable()->index()->after('motivo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hour_contributions', 'transfer_group_id')) {
            Schema::table('hour_contributions', function (Blueprint $table) {
                $table->dropColumn('transfer_group_id');
            });
        }
    }
};
