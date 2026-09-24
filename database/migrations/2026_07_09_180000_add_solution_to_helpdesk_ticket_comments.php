<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Detalhamento da Solução" — formulário obrigatório ao RESOLVER o chamado.
 * Guarda as 3 seções estruturadas (diagnostico/acao/validacao, cada uma HTML com prints)
 * para reabrir como FORMULÁRIO na edição. O `body` da interação segue com o HTML composto
 * (renderiza como texto na timeline).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_ticket_comments') && !Schema::hasColumn('helpdesk_ticket_comments', 'solution')) {
            Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
                $table->json('solution')->nullable()->after('body');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('helpdesk_ticket_comments', 'solution')) {
            Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
                $table->dropColumn('solution');
            });
        }
    }
};
