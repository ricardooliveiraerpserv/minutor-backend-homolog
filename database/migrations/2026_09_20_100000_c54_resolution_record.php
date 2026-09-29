<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connector-5.4 — resolução HUMANA first-class. Coluna aditiva `resolution` (jsonb) em connector_operations:
 * guarda {disposition, reason, resolved_by, at, before:{status,reconciliation_state,outcome_authority}}.
 * A resolução FECHA o incidente (remove a trava) e PRESERVA a evidência observada (reconciliation_state NÃO é
 * sobrescrito). NUNCA reescreve o passado para success (autoridade física = C-2). Sem bytes/path/credencial.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('connector_operations') && ! Schema::hasColumn('connector_operations', 'resolution')) {
            Schema::table('connector_operations', function (Blueprint $t) {
                $t->jsonb('resolution')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('connector_operations', 'resolution')) {
            Schema::table('connector_operations', fn (Blueprint $t) => $t->dropColumn('resolution'));
        }
    }
};
