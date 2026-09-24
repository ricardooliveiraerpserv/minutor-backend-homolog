<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $t) {
            // Agendamento: data (obrigatória) + hora (opcional). Ao agendar, o SLA pausa.
            $t->timestamp('scheduled_until')->nullable()->after('resolution_breached');
            $t->boolean('scheduled_all_day')->default(false)->after('scheduled_until'); // hora omitida
            // Instante em que a PAUSA por agendamento começou (null = não pausado por agendamento).
            $t->timestamp('sla_paused_at')->nullable()->after('scheduled_all_day');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $t) {
            $t->dropColumn(['scheduled_until', 'scheduled_all_day', 'sla_paused_at']);
        });
    }
};
