<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('consultant_hour_bank_closings', 'conflict_hours')) {
            Schema::table('consultant_hour_bank_closings', function (Blueprint $table) {
                // Horas apontadas dentro de 09h–18h acima do teto de 9h/dia (conflito de
                // apontamentos sobrepostos em clientes diferentes) que NÃO são
                // contabilizadas no banco. Congela o valor no snapshot do fechamento.
                $table->decimal('conflict_hours', 8, 2)->default(0)->after('worked_hours');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('consultant_hour_bank_closings', 'conflict_hours')) {
            Schema::table('consultant_hour_bank_closings', function (Blueprint $table) {
                $table->dropColumn('conflict_hours');
            });
        }
    }
};
