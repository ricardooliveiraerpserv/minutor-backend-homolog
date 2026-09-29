<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo opcional entre apontamento e chamado de Help Desk. Aditiva e nullable:
 * apontamentos existentes não mudam. O apontamento continua sendo criado pelo fluxo
 * OFICIAL (TimesheetController::store); o Help Desk apenas inicia o processo e marca o
 * vínculo. Banco de horas / fechamento / Customer 360 leem `timesheets` direto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('timesheets', 'helpdesk_ticket_id')) {
            return;
        }
        Schema::table('timesheets', function (Blueprint $table) {
            $table->foreignId('helpdesk_ticket_id')->nullable()->after('ticket')
                ->constrained('helpdesk_tickets')->nullOnDelete();
            $table->index('helpdesk_ticket_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('timesheets', 'helpdesk_ticket_id')) {
            return;
        }
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('helpdesk_ticket_id');
        });
    }
};
