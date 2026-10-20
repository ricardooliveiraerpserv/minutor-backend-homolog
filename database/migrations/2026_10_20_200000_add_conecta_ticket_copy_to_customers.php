<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo "CÓPIA" dos novos chamados do cliente migrado: o chamado cai NORMALMENTE na fila do HD
 * da ERPSERV E uma cópia é empurrada pra Conecta (incremental). Combina com conecta_ticket_log_only:
 *  - log_only=true           → "só log" (fora da fila; só Conecta).
 *  - log_only=false, copy=true  → "cópia" (na fila + cópia pra Conecta).
 *  - log_only=false, copy=false → "fila" (só na fila da ERPSERV).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'conecta_ticket_copy')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->boolean('conecta_ticket_copy')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'conecta_ticket_copy')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('conecta_ticket_copy');
            });
        }
    }
};
