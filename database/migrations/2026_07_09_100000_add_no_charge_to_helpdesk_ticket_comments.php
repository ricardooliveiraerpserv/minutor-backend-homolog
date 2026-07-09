<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — flag "não gera cobrança" por interação. Quando TRUE, a interação pode
 * registrar o tempo trabalhado (histórico), mas NÃO movimenta horas do contrato
 * (não gera apontamento), mesmo sendo resposta ao cliente com integração ligada.
 * Aditiva/nullable: interações existentes seguem cobrando (false).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_ticket_comments')) {
            return;
        }
        if (!Schema::hasColumn('helpdesk_ticket_comments', 'no_charge')) {
            Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
                $table->boolean('no_charge')->default(false)->after('effort_minutes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('helpdesk_ticket_comments', 'no_charge')) {
            Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
                $table->dropColumn('no_charge');
            });
        }
    }
};
