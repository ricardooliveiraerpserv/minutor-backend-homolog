<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Detalhe das alterações de um aditivo MÚLTIPLO (Banco de Horas Mensal):
            // [{ field, label, old, new }] p/ valor-hora e horas — exibir o breakdown no card.
            if (!Schema::hasColumn('contracts', 'aditivo_changes')) {
                $table->json('aditivo_changes')->nullable()->after('aditivo_field');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'aditivo_changes')) {
                $table->dropColumn('aditivo_changes');
            }
        });
    }
};
