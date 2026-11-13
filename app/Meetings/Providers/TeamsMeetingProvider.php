<?php

namespace App\Meetings\Providers;

use App\Models\Meeting;
use App\Models\UserIntegration;
use App\Services\MicrosoftCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Adapter Microsoft Teams — DELEGADO. Cria a reunião como um EVENTO no calendário do ORGANIZADOR
 * (o usuário logado) usando a MESMA credencial da Agenda do "Meu Dia" (microsoft_calendar /
 * user_integrations). Uma chamada /me/events com isOnlineMeeting=true já gera o join link do Teams,
 * entra no calendário do usuário e dispara os convites. Sem app-only / sem Application Access Policy.
 *
 * Pré-requisitos (Fase 2): services.meetings.teams_enabled=true + microsoft_calendar configurado +
 * o organizador ter conectado a conta Microsoft no Meu Dia com escopo de escrita (Calendars.ReadWrite).
 *
 * DEGRADAÇÃO GRACIOSA: desligado (Fase 1) ou sem conta conectada → reunião criada SEM link;
 * provider_data marca o motivo p/ a UI orientar a conexão.
 */
class TeamsMeetingProvider implements MeetingProvider
{
    public static function enabled(): bool
    {
        return (bool) config('services.meetings.teams_enabled') && MicrosoftCalendarService::configured();
    }

    public function create(Meeting $meeting): array
    {
        if (!self::enabled()) {
            return ['external_meeting_id' => null, 'join_url' => null, 'provider_data' => ['reason' => 'disabled']];
        }

        $token = $this->tokenFor($meeting);
        if (!$token) {
            return ['external_meeting_id' => null, 'join_url' => null, 'provider_data' => ['reason' => 'not_connected']];
        }

        $payload = $this->payload($meeting);
        // Orçamento de tempo: o proxy corta a requisição em ~60s (504). 1ª tentativa até 25s.
        $ev = MicrosoftCalendarService::createEvent($token, $payload, 25);
        if (!$ev) {
            $err = MicrosoftCalendarService::$lastError;
            return ['external_meeting_id' => null, 'join_url' => null, 'provider_data' => [
                'reason' => 'graph_error',
                'graph'  => $err,                                          // motivo devolvido pela Microsoft
                'attendees' => count($payload['attendees']),
                'scopes' => MicrosoftCalendarService::tokenScopes($token), // permissões da conexão do organizador
                // Diagnóstico: a agenda em si funciona? (evento simples, sem Teams, apagado em seguida)
                'probe'  => $this->probePlainEvent($token, $payload),
            ]];
        }

        return [
            'external_meeting_id' => $ev['id'] ?? null,
            'join_url'            => data_get($ev, 'onlineMeeting.joinUrl'),
            'provider_data'       => ['event_id' => $ev['id'] ?? null, 'web_link' => $ev['webLink'] ?? null, 'organizer' => $this->organizerEmail($meeting)],
        ];
    }

    /**
     * Cria um evento SEM reunião online e SEM convidados (ninguém é avisado) e o apaga logo depois.
     * Separa "a agenda da conta está fora" de "só a geração do link do Teams falha".
     * @return array{calendar_ok: bool, error?: ?array, teams?: ?array}
     */
    private function probePlainEvent(string $token, array $payload): array
    {
        $ev = MicrosoftCalendarService::createEvent($token, [
            'subject' => '[Minutor] teste de agenda — pode ignorar',
            'start'   => $payload['start'],
            'end'     => $payload['end'],
            'showAs'  => 'free',
            'isReminderOn' => false,
        ], 12);
        if (!$ev || empty($ev['id'])) {
            return ['calendar_ok' => false, 'error' => MicrosoftCalendarService::$lastError];
        }
        MicrosoftCalendarService::deleteEvent($token, $ev['id'], 8);
        Log::warning('📅 [MEETINGS] agenda OK mas Teams falhou', ['event' => $ev['id']]);
        return ['calendar_ok' => true, 'teams' => MicrosoftCalendarService::calendarTeamsInfo($token)];
    }

    public function update(Meeting $meeting): array
    {
        if (!self::enabled() || !$meeting->external_meeting_id) return [];
        $token = $this->tokenFor($meeting);
        if ($token) {
            MicrosoftCalendarService::updateEvent($token, $meeting->external_meeting_id, [
                'start' => $this->dt($meeting->starts_at, $meeting->timezone),
                'end'   => $this->dt($meeting->ends_at, $meeting->timezone),
            ]);
        }
        return [];
    }

    public function cancel(Meeting $meeting): void
    {
        if (!self::enabled() || !$meeting->external_meeting_id) return;
        $token = $this->tokenFor($meeting);
        if ($token) {
            MicrosoftCalendarService::deleteEvent($token, $meeting->external_meeting_id);
        }
    }

    /** Usuário cujo calendário hospeda a reunião: organizador se houver, senão quem criou. */
    private function actingUserId(Meeting $meeting): ?int
    {
        return $meeting->organizer_user_id ?: $meeting->created_by_id;
    }

    private function organizerEmail(Meeting $meeting): ?string
    {
        $uid = $this->actingUserId($meeting);
        return $uid ? optional(\App\Models\User::find($uid))->email : null;
    }

    /** Token delegado válido do organizador (renova se preciso). null se não tiver conta conectada. */
    private function tokenFor(Meeting $meeting): ?string
    {
        $uid = $this->actingUserId($meeting);
        if (!$uid) return null;

        $integ = UserIntegration::where('user_id', $uid)->where('provider', 'microsoft')->first();
        if (!$integ) {
            Log::info('📅 [MEETINGS] organizador sem conta Microsoft conectada', ['user_id' => $uid, 'meeting_id' => $meeting->id]);
            return null;
        }
        return MicrosoftCalendarService::freshTokenFor($integ);
    }

    private function payload(Meeting $meeting): array
    {
        $attendees = $meeting->participantRows
            ->filter(fn ($p) => filled($p->email) && $p->role !== 'organizer')
            ->map(fn ($p) => [
                'emailAddress' => ['address' => $p->email, 'name' => $p->name ?: $p->email],
                'type'         => $p->role === 'optional' ? 'optional' : 'required',
            ])->values()->all();

        return [
            'subject' => $meeting->title,
            'body'    => ['contentType' => 'HTML', 'content' => $meeting->description ?: ''],
            'start'   => $this->dt($meeting->starts_at, $meeting->timezone),
            'end'     => $this->dt($meeting->ends_at, $meeting->timezone),
            'attendees' => $attendees,
            'isOnlineMeeting' => true,
            'onlineMeetingProvider' => 'teamsForBusiness',
            'allowNewTimeProposals' => false,
        ];
    }

    private function dt($carbon, ?string $tz): array
    {
        return ['dateTime' => Carbon::parse($carbon)->timezone($tz ?: 'America/Sao_Paulo')->format('Y-m-d\TH:i:s'), 'timeZone' => $tz ?: 'America/Sao_Paulo'];
    }
}
