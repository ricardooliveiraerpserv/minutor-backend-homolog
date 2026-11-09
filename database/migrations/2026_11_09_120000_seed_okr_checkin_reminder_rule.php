<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lembrete recorrente do check-in mensal de OKR. Nasce LIGADO, a cada 3 dias: só alcança quem tem
 * KR pendente no mês anterior (OkrCheckinReminderService), então fora do início do mês não dispara.
 * Ajustável pelo admin na rotina de lembretes.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('action_reminder_rules')) return;
        if (DB::table('action_reminder_rules')->where('key', 'okr_checkin')->exists()) return;
        DB::table('action_reminder_rules')->insert([
            'key' => 'okr_checkin', 'enabled' => true, 'unit' => 'days', 'interval' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('action_reminder_rules')->where('key', 'okr_checkin')->delete();
    }
};
