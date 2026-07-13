<?php

namespace App\Console\Commands;

use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketEvent;
use App\Services\HelpDeskSlaService;
use Illuminate\Console\Command;

/**
 * Retoma o SLA de chamados AGENDADOS cuja data/hora de retomada já venceu:
 *  - assa a pausa nos prazos (resumeSchedule) e limpa o agendamento;
 *  - MUDA o status para "Em atendimento" (em_andamento) — ao chegar a hora, volta pro fluxo.
 * Agendado a cada 5 min.
 */
class HelpDeskResumeScheduled extends Command
{
    protected $signature = 'help-desk:resume-scheduled';
    protected $description = 'Retoma o SLA de chamados agendados cuja janela já venceu (→ Em atendimento)';

    public function handle(HelpDeskSlaService $sla): int
    {
        $em = HelpDeskStatus::where('key', 'em_andamento')->first();

        $due = HelpDeskTicket::query()
            ->whereNotNull('scheduled_until')
            ->where('scheduled_until', '<=', now())
            ->with('status')
            ->limit(500)->get();

        foreach ($due as $ticket) {
            $sla->resumeSchedule($ticket);
            HelpDeskTicketEvent::log($ticket->id, 'schedule_resumed', ['meta' => ['auto' => true]]);

            // Ao chegar o período do agendamento, volta para "Em atendimento" (não mexe se já
            // estiver encerrado/resolvido — não reabre por engano).
            if ($em && (int) $ticket->status_id !== (int) $em->id
                && !optional($ticket->status)->is_terminal && !optional($ticket->status)->is_resolved) {
                $old = $ticket->status;
                $ticket->status_id = $em->id;
                $ticket->last_activity_at = now();
                $sla->computeBreaches($ticket);
                $ticket->save();
                HelpDeskTicketEvent::log($ticket->id, 'status_changed', [
                    'field' => 'status', 'from_value' => $old?->key, 'to_value' => $em->key,
                    'meta' => ['auto' => true, 'reason' => 'schedule_due'],
                ]);
            }
        }

        $this->info("Agendamentos retomados: {$due->count()}.");
        return self::SUCCESS;
    }
}
