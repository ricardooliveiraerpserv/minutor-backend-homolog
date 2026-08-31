<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca no apontamento-PAI do servidor de rateio que a divisão foi ajustada MANUALMENTE
 * (override). A re-distribuição retroativa (ao salvar períodos) pula os que têm este flag,
 * preservando os ajustes manuais.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('timesheets', 'rateio_overridden')) {
            Schema::table('timesheets', function (Blueprint $table) {
                $table->boolean('rateio_overridden')->default(false)->after('rateio_source_timesheet_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('timesheets', 'rateio_overridden')) {
            Schema::table('timesheets', fn (Blueprint $t) => $t->dropColumn('rateio_overridden'));
        }
    }
};
