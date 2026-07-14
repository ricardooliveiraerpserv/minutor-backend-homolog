<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Status "Reunião agendada" — quando uma reunião é agendada a partir de um chamado, ele entra neste
 * status: PAUSA o SLA (sla_paused=true) e permite agendamento (scheduled_until = término da reunião).
 * Ao vencer a janela, HelpDeskResumeScheduled retoma o SLA e volta pra "Em atendimento".
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('helpdesk_statuses')->where('key', 'reuniao_agendada')->exists();
        if ($exists) return;

        // posiciona logo após "Em atendimento" (em_andamento)
        $ref = DB::table('helpdesk_statuses')->where('key', 'em_andamento')->value('sort_order');
        $sort = ($ref ?? 10) + 1;

        DB::table('helpdesk_statuses')->insert([
            'key'               => 'reuniao_agendada',
            'label'             => 'Reunião agendada',
            'color'            => '#6366f1', // indigo — combina com o card de reunião
            'sort_order'        => $sort,
            'is_default'        => false,
            'is_open'           => true,
            'is_resolved'       => false,
            'is_terminal'       => false,
            'sla_paused'        => true,
            'allows_scheduling' => true,
            'active'            => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('helpdesk_statuses')->where('key', 'reuniao_agendada')->delete();
    }
};
