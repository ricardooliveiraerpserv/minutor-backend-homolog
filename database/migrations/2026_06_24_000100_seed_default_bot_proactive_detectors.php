<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            [
                'slug'                => 'bank_hours_critical',
                'name'                => 'Banco de horas crítico',
                'description'         => 'Detecta consultores com saldo absoluto acima do limite no mês corrente.',
                'active'              => true,
                'detector_type'       => 'bank_hours_threshold',
                'config'              => json_encode(['threshold_hours' => 16]),
                'severity'            => 'high',
                'source'              => 'ai',
                'event_type'          => 'financial_alert',
                'dedupe_window_hours' => 24,
                'is_system'           => true,
            ],
            [
                'slug'                => 'expense_payment_overdue',
                'name'                => 'Despesas aprovadas sem pagamento',
                'description'         => 'Despesas aprovadas há mais de N dias sem pagamento.',
                'active'              => true,
                'detector_type'       => 'expense_payment_age',
                'config'              => json_encode(['days' => 7]),
                'severity'            => 'medium',
                'source'              => 'ai',
                'event_type'          => 'financial_alert',
                'dedupe_window_hours' => 24,
                'is_system'           => true,
            ],
            [
                'slug'                => 'timesheets_pending_long',
                'name'                => 'Apontamentos pendentes há muito tempo',
                'description'         => 'Apontamentos aguardando aprovação há mais de N dias.',
                'active'              => true,
                'detector_type'       => 'timesheet_pending_age',
                'config'              => json_encode(['days' => 5]),
                'severity'            => 'medium',
                'source'              => 'ai',
                'event_type'          => 'financial_alert',
                'dedupe_window_hours' => 24,
                'is_system'           => true,
            ],
            [
                'slug'                => 'tickets_stale',
                'name'                => 'Tickets do Movidesk parados',
                'description'         => 'Tickets em aberto sem update há mais de N dias.',
                'active'              => true,
                'detector_type'       => 'ticket_stale_age',
                'config'              => json_encode(['days' => 3]),
                'severity'            => 'high',
                'source'              => 'movidesk',
                'event_type'          => 'sla_breach',
                'dedupe_window_hours' => 24,
                'is_system'           => true,
            ],
            [
                'slug'                => 'timesheets_late',
                'name'                => 'Apontamentos com status late',
                'description'         => 'Apontamentos marcados como late precisam revisão.',
                'active'              => true,
                'detector_type'       => 'late_timesheets',
                'config'              => json_encode([]),
                'severity'            => 'medium',
                'source'              => 'ai',
                'event_type'          => 'financial_alert',
                'dedupe_window_hours' => 24,
                'is_system'           => true,
            ],
        ];

        $now = now();
        foreach ($defaults as $row) {
            $exists = DB::table('bot_proactive_detectors')->where('slug', $row['slug'])->exists();
            if ($exists) continue;
            DB::table('bot_proactive_detectors')->insert(array_merge($row, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        DB::table('bot_proactive_detectors')->whereIn('slug', [
            'bank_hours_critical',
            'expense_payment_overdue',
            'timesheets_pending_long',
            'tickets_stale',
            'timesheets_late',
        ])->delete();
    }
};
