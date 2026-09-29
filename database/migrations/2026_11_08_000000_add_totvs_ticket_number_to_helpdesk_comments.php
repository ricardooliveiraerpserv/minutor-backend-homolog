<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Número do chamado da TOTVS informado na interação ao mover p/ "Pendente TOTVS".
        // Fica na INTERAÇÃO (pode haver vários por chamado, um por interação). Indexado p/ filtro.
        if (!Schema::hasColumn('helpdesk_ticket_comments', 'totvs_ticket_number')) {
            Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
                $table->string('totvs_ticket_number')->nullable()->after('body');
                $table->index('totvs_ticket_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('helpdesk_ticket_comments', 'totvs_ticket_number')) {
            Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
                $table->dropIndex(['totvs_ticket_number']);
                $table->dropColumn('totvs_ticket_number');
            });
        }
    }
};
