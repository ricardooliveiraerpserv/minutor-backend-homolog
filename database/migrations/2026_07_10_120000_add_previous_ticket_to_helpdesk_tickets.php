<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Continuação de chamado: quando o cliente responde por e-mail um chamado ENCERRADO
 * (fechado/cancelado), abrimos um chamado NOVO ligado ao anterior por `previous_ticket_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('helpdesk_tickets', 'previous_ticket_id')) {
            Schema::table('helpdesk_tickets', function (Blueprint $table) {
                $table->foreignId('previous_ticket_id')->nullable()->after('id')
                    ->constrained('helpdesk_tickets')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('helpdesk_tickets', 'previous_ticket_id')) {
            Schema::table('helpdesk_tickets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('previous_ticket_id');
            });
        }
    }
};
