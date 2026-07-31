<?php

namespace App\Meetings;

use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketEvent;
use App\Models\Meeting;
use App\Services\HelpDeskSlaService;

/**
 * Sincroniza o chamado quando uma reunião VINCULADA a ele muda de estado. Reusa o mecanismo de
 * agendamento/SLA já existente (scheduled_until + resumeSchedule) — NÃO duplica lógica de SLA.
 *
 *  - Reunião agendada  → chamado vai p/ "Reunião agendada" (pausa SLA até o TÉRMINO da reunião) +
 *                        interação no corpo do chamado com os dados da reunião.
 *  - Reunião cancelada → volta o chamado p/ "Em atendimento" e RETOMA o SLA.
 *
 * Ao vencer a janela (término), o command help-desk:resume-scheduled retoma o SLA e volta o status
 * p/ "Em atendimento" automaticamente — mesmo caminho do agendamento manual.
 */
class HelpDeskMeetingSync
{
    public function __construct(private HelpDeskSlaService $sla)
    {
    }

    /** Reunião criada a partir de um chamado → status "Reunião agendada" + pausa SLA até o término. */
    public function onScheduled(Meeting $meeting): void
    {
        $ticket = $this->ticketFor($meeting);
        if (!$ticket) return;
        // Não mexe em chamado encerrado/resolvido.
        if (optional($ticket->status)->is_terminal || optional($ticket->status)->is_resolved) return;

        $status = HelpDeskStatus::where('key', 'reuniao_agendada')->where('active', true)->first();
        if (!$status) return;

        // 1) Interação no corpo do chamado com os dados da reunião.
        $comment = $this->postInteraction($ticket, $meeting);

        // 2) Reagendar sobre agendamento vigente: retoma antes (assa a pausa anterior no prazo).
        if ($ticket->sla_paused_at || $ticket->scheduled_until) {
            $this->sla->resumeSchedule($ticket);
        }

        // 3) Transição de status → "Reunião agendada". Atualiza a relação p/ o SLA avaliar o status NOVO.
        $old = $ticket->status;
        $ticket->status_id = $status->id;
        $ticket->setRelation('status', $status);

        // 4) Agenda até o TÉRMINO da reunião. Se o status novo já pausa o SLA (global/por regra), NÃO
        //    seta sla_paused_at (senão contaria em dobro); senão pausa por agendamento — igual ao manual.
        $ticket->scheduled_until   = $meeting->ends_at ?: $meeting->starts_at;
        $ticket->scheduled_all_day = false;
        $ticket->sla_paused_at     = $this->sla->isPausedByStatus($ticket) ? null : now();
        $ticket->last_activity_at  = now();
        $this->sla->computeBreaches($ticket);
        $ticket->save();

        HelpDeskTicketEvent::log($ticket->id, 'status_changed', [
            'field' => 'status', 'from_value' => $old?->key, 'to_value' => $status->key,
            'meta'  => ['reason' => 'meeting_scheduled', 'meeting_id' => $meeting->id],
        ]);
        HelpDeskTicketEvent::log($ticket->id, 'scheduled', [
            'to_value' => optional($ticket->scheduled_until)->toIso8601String(),
            'meta'     => ['reason' => 'meeting', 'meeting_id' => $meeting->id],
        ]);

        // 5) E-mail do convite pelo MESMO fluxo das interações (threaded) → cliente + participantes + criador.
        $this->dispatchMeetingEmail($ticket, $comment, $meeting);
    }

    /** Reunião cancelada → se o chamado estava "Reunião agendada", volta p/ "Em atendimento" + retoma SLA. */
    public function onCanceled(Meeting $meeting): void
    {
        $ticket = $this->ticketFor($meeting);
        if (!$ticket) return;
        // Só age se o chamado ainda está no status de reunião (não pisa em mudança manual posterior).
        if (optional($ticket->status)->key !== 'reuniao_agendada') return;

        $em = HelpDeskStatus::where('key', 'em_andamento')->first();

        if ($ticket->sla_paused_at || $ticket->scheduled_until) {
            $this->sla->resumeSchedule($ticket);
            HelpDeskTicketEvent::log($ticket->id, 'schedule_resumed', ['meta' => ['reason' => 'meeting_canceled']]);
        }

        if ($em) {
            $old = $ticket->status;
            $ticket->status_id = $em->id;
            $ticket->last_activity_at = now();
            $this->sla->computeBreaches($ticket);
            $ticket->save();
            HelpDeskTicketEvent::log($ticket->id, 'status_changed', [
                'field' => 'status', 'from_value' => $old?->key, 'to_value' => $em->key,
                'meta'  => ['reason' => 'meeting_canceled', 'meeting_id' => $meeting->id],
            ]);
        }

        $comment = $ticket->comments()->create([
            'author_user_id' => $meeting->organizer_user_id ?: $meeting->created_by_id,
            'body'           => $this->canceledBody($meeting),
            'visibility'     => 'customer',
            'channel'        => 'interno',
        ]);

        // E-mail de cancelamento pelo MESMO fluxo das interações (threaded) → cliente + participantes + criador.
        $this->dispatchMeetingEmail($ticket, $comment, $meeting);
    }

    /** Corpo da interação de reunião CANCELADA — mesmo padrão do convite (assunto, quando) + nota do SLA. */
    private function canceledBody(Meeting $meeting): string
    {
        $meeting->loadMissing('organizer');
        $tz  = $meeting->timezone ?: 'America/Sao_Paulo';
        $start = $meeting->starts_at?->copy()->setTimezone($tz);
        $end   = $meeting->ends_at?->copy()->setTimezone($tz);
        $dia = $start?->format('d/m/Y');
        $ini = $start?->format('H:i');
        $fim = $end?->format('H:i');

        $linhas = [];
        $linhas[] = '<strong>Assunto:</strong> ' . e($meeting->title);
        if ($dia) {
            $linhas[] = '<strong>Estava agendada para:</strong> ' . e($dia)
                . ($ini ? ' · ' . e($ini) . ($fim ? ' às ' . e($fim) : '') : '');
        }
        if ($meeting->organizer?->name) $linhas[] = '<strong>Organizador:</strong> ' . e($meeting->organizer->name);

        return '<p>❌ <strong>Reunião cancelada</strong></p>'
            . '<p>' . implode('<br>', $linhas) . '</p>'
            . '<p><em>Voltamos a tratar deste chamado — o atendimento (SLA) foi retomado.</em></p>';
    }

    /**
     * Interação automática com as INFORMAÇÕES DO INVITE — em nome do usuário logado (organizador),
     * VISÍVEL ao cliente (conversa + portal). Retorna o comentário p/ o e-mail ser disparado pelo
     * MESMO fluxo das interações do chamado (threaded).
     */
    private function postInteraction(HelpDeskTicket $ticket, Meeting $meeting): \App\Models\HelpDeskTicketComment
    {
        return $ticket->comments()->create([
            'author_user_id' => $meeting->organizer_user_id ?: $meeting->created_by_id,
            'body'           => $this->inviteBody($meeting),
            'visibility'     => 'customer',
            'channel'        => 'interno',
        ]);
    }

    /** Cc do e-mail de reunião: participantes + criador/organizador (o cliente é o To da interação). */
    private function meetingCc(Meeting $meeting): array
    {
        $meeting->loadMissing('participants', 'organizer', 'createdBy');
        $cc = $meeting->participants->pluck('email')->all();
        if ($meeting->organizer?->email)  $cc[] = $meeting->organizer->email;
        if ($meeting->createdBy?->email)  $cc[] = $meeting->createdBy->email;
        return array_values(array_unique(array_filter(array_map('trim', $cc))));
    }

    /**
     * Dispara o e-mail da interação de reunião pelo MESMO fluxo das respostas do chamado (threaded,
     * HTML, na conversa), com Cc = participantes + criador. Best-effort — não derruba o agendamento.
     */
    private function dispatchMeetingEmail(HelpDeskTicket $ticket, \App\Models\HelpDeskTicketComment $comment, Meeting $meeting): void
    {
        try {
            \App\Jobs\SendHelpDeskEmailJob::dispatch($ticket->id, $comment->id, $this->meetingCc($meeting))
                ->onConnection(config('queue.helpdesk_email_connection'))->onQueue('emails');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('HelpDesk: e-mail de reunião falhou ao despachar: ' . $e->getMessage());
        }
    }

    /** Corpo da interação = convite formatado (assunto, quando, duração, formato, organizador, link, pauta). */
    private function inviteBody(Meeting $meeting): string
    {
        $meeting->loadMissing('participants', 'organizer');
        $prov   = ['teams' => 'Microsoft Teams', 'meet' => 'Google Meet', 'zoom' => 'Zoom', 'webex' => 'Webex', 'presencial' => 'Presencial'][$meeting->provider] ?? $meeting->provider;
        // starts_at/ends_at são UTC (app tz); exibe no fuso da reunião p/ mostrar a hora que o usuário digitou.
        $tz     = $meeting->timezone ?: 'America/Sao_Paulo';
        $start  = $meeting->starts_at?->copy()->setTimezone($tz);
        $end    = $meeting->ends_at?->copy()->setTimezone($tz);
        $dia    = $start?->format('d/m/Y');
        $ini    = $start?->format('H:i');
        $fim    = $end?->format('H:i');
        $dur    = $meeting->duration_minutes;
        $parts  = $meeting->participants->map(fn ($p) => $p->name ?: $p->email)->filter()->implode(', ');

        $linhas = [];
        $linhas[] = '<strong>Assunto:</strong> ' . e($meeting->title);
        $linhas[] = '<strong>Quando:</strong> ' . e($dia) . ($ini ? ' · ' . e($ini) . ($fim ? ' às ' . e($fim) : '') : '') . ($dur ? ' (' . $dur . ' min)' : '');
        $linhas[] = '<strong>Formato:</strong> ' . e($prov);
        if ($meeting->organizer?->name) $linhas[] = '<strong>Organizador:</strong> ' . e($meeting->organizer->name);
        if ($parts) $linhas[] = '<strong>Participantes:</strong> ' . e($parts);

        $body = '<p>Uma reunião foi agendada para tratarmos deste chamado. Segue o convite:</p>'
            . '<p>' . implode('<br>', $linhas) . '</p>';

        if ($meeting->join_url) {
            $body .= '<p>🔗 <a href="' . e($meeting->join_url) . '"><strong>Entrar na reunião</strong></a></p>';
        }
        if (filled($meeting->description)) {
            $body .= '<p><strong>Pauta:</strong><br>' . nl2br(e($meeting->description)) . '</p>';
        }
        $body .= '<p><em>O atendimento (SLA) fica pausado até o término da reunião.</em></p>';

        return $body;
    }

    private function ticketFor(Meeting $meeting): ?HelpDeskTicket
    {
        if ($meeting->origin_type !== 'HELPDESK_TICKET' || !$meeting->origin_id) return null;
        return HelpDeskTicket::with('status')->find($meeting->origin_id);
    }
}
