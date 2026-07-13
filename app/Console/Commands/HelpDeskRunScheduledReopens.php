<?php

namespace App\Console\Commands;

use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketEvent;
use App\Services\HelpDeskSlaService;
use App\Services\HelpDeskTriggerEngine;
use Illuminate\Console\Command;

/**
 * Reabertura agendada: reabre automaticamente os chamados RESOLVIDOS/ENCERRADOS cuja
 * data/hora de reabertura já venceu. Volta para "Em atendimento", incrementa reopen_count,
 * limpa o agendamento e dispara o gatilho de mudança de status. Agendado a cada 5 min.
 */
class HelpDeskRunScheduledReopens extends Command
{
    protected $signature = 'help-desk:run-scheduled-reopens';
    protected $description = 'Reabre chamados com reabertura agendada cuja hora já chegou (→ Em atendimento)';

    public function handle(HelpDeskSlaService $sla): int
    {
        $em = HelpDeskStatus::where('key', 'em_andamento')->first();
        if (!$em) { $this->error('Status "em_andamento" não encontrado.'); return self::FAILURE; }

        $due = HelpDeskTicket::query()
            ->whereNotNull('reopen_scheduled_at')
            ->where('reopen_scheduled_at', '<=', now())
            ->with('status')
            ->limit(500)->get();

        $count = 0;
        foreach ($due as $ticket) {
            $old = $ticket->status;
            $note = $ticket->reopen_scheduled_note;

            // Só reabre se ainda estiver resolvido/encerrado (evita reabrir algo já reaberto à mão).
            if ($old && ($old->is_resolved || $old->is_terminal)) {
                $ticket->status_id    = $em->id;
                $ticket->reopened_at  = now();
                $ticket->resolved_at  = null;
                $ticket->closed_at    = null;
                $ticket->reopen_count = (int) $ticket->reopen_count + 1;
                $ticket->last_activity_at = now();
                // Limpa o agendamento ANTES de recomputar/salvar.
                $ticket->reopen_scheduled_at   = null;
                $ticket->reopen_scheduled_note = null;
                $ticket->reopen_scheduled_by_id = null;
                $sla->computeBreaches($ticket);
                $ticket->save();

                HelpDeskTicketEvent::log($ticket->id, 'reopened', ['to_value' => $em->label, 'meta' => ['auto' => true, 'scheduled' => true]]);
                HelpDeskTicketEvent::log($ticket->id, 'status_changed', [
                    'field' => 'status', 'from_value' => $old->key, 'to_value' => $em->key,
                    'meta' => ['auto' => true, 'reason' => 'scheduled_reopen', 'note' => $note],
                ]);
                HelpDeskTriggerEngine::dispatch('status_changed', $ticket->fresh(), ['actor_id' => null, 'actor_email' => null]);
                $count++;
            } else {
                // Status mudou no intervalo — só descarta o agendamento pendente.
                $ticket->reopen_scheduled_at   = null;
                $ticket->reopen_scheduled_note = null;
                $ticket->reopen_scheduled_by_id = null;
                $ticket->save();
            }
        }

        $this->info("Reaberturas agendadas processadas: {$count}.");
        return self::SUCCESS;
    }
}
