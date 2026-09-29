<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Âncora de threading do e-mail do Help Desk: guarda o id (Graph) da mensagem do cliente
 * para responder na MESMA conversa (createReply) — confirmação e respostas caem num e-mail
 * único na caixa do cliente. O id Graph é longo (>120), por isso text.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('helpdesk_tickets', 'graph_thread_msg_id')) {
            Schema::table('helpdesk_tickets', function (Blueprint $table) {
                $table->text('graph_thread_msg_id')->nullable()->after('external_ref');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('helpdesk_tickets', 'graph_thread_msg_id')) {
            Schema::table('helpdesk_tickets', function (Blueprint $table) {
                $table->dropColumn('graph_thread_msg_id');
            });
        }
    }
};
