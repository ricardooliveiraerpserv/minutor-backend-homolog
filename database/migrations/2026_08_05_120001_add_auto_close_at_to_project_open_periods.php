<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reabertura MENSAL passa a auto-fechar às 23:59 do dia da reabertura (pedido Ricardo).
 * Legado com auto_close_at NULL = fica aberto até fechar manualmente (não quebra o passado);
 * reaberturas novas sempre setam auto_close_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_open_periods') && !Schema::hasColumn('project_open_periods', 'auto_close_at')) {
            Schema::table('project_open_periods', function (Blueprint $table) {
                $table->timestamp('auto_close_at')->nullable()->after('closed_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_open_periods', 'auto_close_at')) {
            Schema::table('project_open_periods', function (Blueprint $table) {
                $table->dropColumn('auto_close_at');
            });
        }
    }
};
