<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migração Conecta — modo de roteamento dos NOVOS chamados do cliente migrado:
 *  - false (padrão): mantém o fluxo na ERPSERV — o chamado entra na fila E espelha na Conecta.
 *  - true: "só log" — o chamado NÃO entra na fila da ERPSERV; grava a linha de log e é
 *    empurrado pra Conecta (nasce lá). Não afeta clientes não migrados.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'conecta_ticket_log_only')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->boolean('conecta_ticket_log_only')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'conecta_ticket_log_only')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('conecta_ticket_log_only');
            });
        }
    }
};
