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
        $ev = MicrosoftCalendarService::createEvent($token, $payload);
        $extra = [];
        if (!$ev) {
            $firstError = MicrosoftCalendarService::$lastError;
            // 5xx/timeout do Graph com o payload completo (muitos convidados / corpo HTML grande):
            // tenta em 2 etapas — cria a reunião enxuta (só assunto/horário/Teams) e depois
            // adiciona convidados + texto via PATCH (é o PATCH que dispara os convites).
            $retryable = ($firstError['status'] ?? 0) === 0 || ($firstError['status'] ?? 0) >= 500;
            if ($retryable) {
                [$ev, $extra] = $this->createInTwoSteps($token, $payload);
            }
            if (!$ev) {
                return ['external_meeting_id' => null, 'join_url' => null, 'provider_data' => [
                    'reason' => 'graph_error',
                    'graph'  => MicrosoftCalendarService::$lastError ?? $firstError, // motivo devolvido pela Microsoft
                    'first_graph' => $firstError,
                    'attendees' => count($payload['attendees']),
                    'body_kb'   => (int) ceil(strlen($payload['body']['content'] ?? '') / 1024),
                    'scopes' => MicrosoftCalendarService::tokenScopes($token), // permissões da conexão do organizador
                ]];
            }
            $extra['first_graph'] = $firstError;
        }

        return [
            'external_meeting_id' => $ev['id'] ?? null,
            'join_url'            => data_get($ev, 'onlineMeeting.joinUrl'),
            'provider_data'       => ['event_id' => $ev['id'] ?? null, 'web_link' => $ev['webLink'] ?? null, 'organizer' => $this->organizerEmail($meeting)] + $extra,
        ];
    }

    /** @return array{0: ?array, 1: array} [evento, info extra p/ provider_data] */
    private function createInTwoSteps(string $token, array $payload): array
    {
        $min = [
            'subject' => $payload['subject'],
            'start'   => $payload['start'],
            'end'     => $payload['end'],
            'isOnlineMeeting' => true,
            'onlineMeetingProvider' => 'teamsForBusiness',
            'allowNewTimeProposals' => false,
            'transactionId' => substr(($payload['transactionId'] ?? 'minutor-') . '-2s', 0, 40),
        ];
        $ev = MicrosoftCalendarService::createEvent($token, $min);
        if (!$ev || empty($ev['id'])) return [null, []];

        $patch = ['attendees' => $payload['attendees'], 'body' => $payload['body']];
        $ok = MicrosoftCalendarService::updateEvent($token, $ev['id'], $patch, 60);
        if (!$ok) {
            // Corpo pode ser o problema: tenta só os convidados (o essencial — o convite).
            $ok = MicrosoftCalendarService::updateEvent($token, $ev['id'], ['attendees' => $payload['attendees']], 60);
        }
        Log::info('📅 [MEETINGS] Teams criado em 2 etapas', ['event' => $ev['id'], 'invites_ok' => (bool) $ok, 'attendees' => count($payload['attendees'])]);
        return [$ok ?: $ev, ['two_step' => true, 'invites_failed' => !$ok]];
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
            // Idempotência: mesma reunião (organizador+assunto+início) não vira 2 eventos se a 1ª
            // chamada estourar o tempo e o usuário tentar de novo.
            'transactionId' => 'minutor-' . substr(sha1(($this->actingUserId($meeting) ?? 0) . '|' . $meeting->title . '|' . Carbon::parse($meeting->starts_at)->toIso8601String()), 0, 32),
        ];
    }

    private function dt($carbon, ?string $tz): array
    {
        return ['dateTime' => Carbon::parse($carbon)->timezone($tz ?: 'America/Sao_Paulo')->format('Y-m-d\TH:i:s'), 'timeZone' => $tz ?: 'America/Sao_Paulo'];
    }
}
