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
 * Pré-requisitos: microsoft_calendar configurado (client_id/secret) + o organizador ter conectado a
 * conta Microsoft no Meu Dia com escopo de escrita (Calendars.ReadWrite).
 *
 * DEGRADAÇÃO GRACIOSA: se o organizador não tiver conta conectada (ou o token não renovar), a reunião
 * é criada mesmo assim SEM link — provider_data marca o motivo p/ a UI orientar a conexão.
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

        $ev = MicrosoftCalendarService::createEvent($token, $this->payload($meeting));
        if (!$ev) {
            return ['external_meeting_id' => null, 'join_url' => null, 'provider_data' => ['reason' => 'graph_error']];
        }

        return [
            'external_meeting_id' => $ev['id'] ?? null,
            'join_url'            => data_get($ev, 'onlineMeeting.joinUrl'),
            'provider_data'       => ['event_id' => $ev['id'] ?? null, 'web_link' => $ev['webLink'] ?? null, 'organizer' => $this->organizerEmail($meeting)],
        ];
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
        $attendees = $meeting->participants
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
