<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência da criação de interação — evita comentários DUPLICADOS por reenvio
 * (erro→retry, duplo-clique, request lento que parece ter falhado mas gravou).
 * Mesma estratégia do Finalizar Atendimento (helpdesk_finalize_operations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
            if (!Schema::hasColumn('helpdesk_ticket_comments', 'idempotency_key')) {
                $table->string('idempotency_key', 80)->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
            if (Schema::hasColumn('helpdesk_ticket_comments', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
