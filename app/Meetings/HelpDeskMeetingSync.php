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
        $this->postInteraction($ticket, $meeting);

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

        $ticket->comments()->create([
            'author_user_id' => $meeting->created_by_id,
            'body'           => '<p>❌ <strong>Reunião cancelada</strong> — ' . e($meeting->title) . '</p>',
            'visibility'     => 'customer',
            'channel'        => 'interno',
        ]);
    }

    /**
     * Interação automática com as INFORMAÇÕES DO INVITE — em nome do usuário logado (organizador),
     * VISÍVEL ao cliente (conversa + portal). Criada direto no model → NÃO dispara e-mail; o convite
     * do Teams já vai pelos participantes.
     */
    private function postInteraction(HelpDeskTicket $ticket, Meeting $meeting): void
    {
        $ticket->comments()->create([
            'author_user_id' => $meeting->organizer_user_id ?: $meeting->created_by_id,
            'body'           => $this->inviteBody($meeting),
            'visibility'     => 'customer',
            'channel'        => 'interno',
        ]);
    }

    /** Corpo da interação = convite formatado (assunto, quando, duração, formato, organizador, link, pauta). */
    private function inviteBody(Meeting $meeting): string
    {
        $meeting->loadMissing('participants', 'organizer');
        $prov   = ['teams' => 'Microsoft Teams', 'meet' => 'Google Meet', 'zoom' => 'Zoom', 'webex' => 'Webex', 'presencial' => 'Presencial'][$meeting->provider] ?? $meeting->provider;
        $dia    = optional($meeting->starts_at)->format('d/m/Y');
        $ini    = optional($meeting->starts_at)->format('H:i');
        $fim    = optional($meeting->ends_at)->format('H:i');
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
