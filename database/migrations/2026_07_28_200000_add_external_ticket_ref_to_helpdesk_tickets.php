<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referência externa do chamado no fornecedor (ex.: nº do chamado aberto na TOTVS quando o
 * status vai para "Pendente terceiros" via a justificativa "Pendente TOTVS").
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('helpdesk_tickets', 'external_ticket_ref')) {
            Schema::table('helpdesk_tickets', function (Blueprint $table) {
                $table->string('external_ticket_ref', 100)->nullable()->after('graph_thread_msg_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('helpdesk_tickets', 'external_ticket_ref')) {
            Schema::table('helpdesk_tickets', function (Blueprint $table) {
                $table->dropColumn('external_ticket_ref');
            });
        }
    }
};
