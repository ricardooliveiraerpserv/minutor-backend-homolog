<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estorno passa a MARCAR o lançamento (reversed_at) em vez de apagar — some do
 * histórico, mas fica guardado p/ reenvio do comunicado de estorno.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['contract_value_changes', 'manual_reajuste_value_changes'] as $t) {
            if (Schema::hasTable($t) && !Schema::hasColumn($t, 'reversed_at')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->timestamp('reversed_at')->nullable()->after('user_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['contract_value_changes', 'manual_reajuste_value_changes'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'reversed_at')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn('reversed_at'));
            }
        }
    }
};
