<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reabertura agendada: agenda um chamado RESOLVIDO/ENCERRADO para reabrir automaticamente
 * numa data/hora futura (ex.: acompanhamento). Um job periódico reabre quando a hora chega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('helpdesk_tickets', 'reopen_scheduled_at')) {
                $table->timestampTz('reopen_scheduled_at')->nullable()->index();
            }
            if (!Schema::hasColumn('helpdesk_tickets', 'reopen_scheduled_note')) {
                $table->string('reopen_scheduled_note', 500)->nullable();
            }
            if (!Schema::hasColumn('helpdesk_tickets', 'reopen_scheduled_by_id')) {
                $table->unsignedBigInteger('reopen_scheduled_by_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            foreach (['reopen_scheduled_at', 'reopen_scheduled_note', 'reopen_scheduled_by_id'] as $col) {
                if (Schema::hasColumn('helpdesk_tickets', $col)) $table->dropColumn($col);
            }
        });
    }
};
