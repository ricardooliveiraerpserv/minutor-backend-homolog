<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — tempo trabalhado POR interação (substitui o Movidesk).
 * Cada resposta/nota pode carregar Data + Hora início + Hora fim + Total.
 * Quando o contrato de sustentação tem a chave de integração ligada, a interação
 * gera um apontamento oficial (timesheets) e guarda o vínculo em timesheet_id.
 * Tudo nullable/aditivo: interações existentes não mudam.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_ticket_comments')) {
            return;
        }
        Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
            if (!Schema::hasColumn('helpdesk_ticket_comments', 'worked_date')) {
                $table->date('worked_date')->nullable()->after('channel');
            }
            if (!Schema::hasColumn('helpdesk_ticket_comments', 'start_time')) {
                $table->string('start_time', 5)->nullable()->after('worked_date');
            }
            if (!Schema::hasColumn('helpdesk_ticket_comments', 'end_time')) {
                $table->string('end_time', 5)->nullable()->after('start_time');
            }
            if (!Schema::hasColumn('helpdesk_ticket_comments', 'effort_minutes')) {
                $table->integer('effort_minutes')->nullable()->after('end_time');
            }
            if (!Schema::hasColumn('helpdesk_ticket_comments', 'timesheet_id')) {
                $table->foreignId('timesheet_id')->nullable()->after('effort_minutes')
                    ->constrained('timesheets')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('helpdesk_ticket_comments')) {
            return;
        }
        Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
            if (Schema::hasColumn('helpdesk_ticket_comments', 'timesheet_id')) {
                $table->dropConstrainedForeignId('timesheet_id');
            }
            foreach (['effort_minutes', 'end_time', 'start_time', 'worked_date'] as $col) {
                if (Schema::hasColumn('helpdesk_ticket_comments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
