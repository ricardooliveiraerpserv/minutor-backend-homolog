<?php

namespace App\Meetings\Providers;

use App\Models\Meeting;
use App\Models\UserIntegration;
use App\Services\MicrosoftCalendarService;
use App\Services\MicrosoftHealth;
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
        $start = Carbon::parse($meeting->starts_at);
        $end   = Carbon::parse($meeting->ends_at);

        // 1) Já existe? (uma tentativa anterior estourou o tempo e a Microsoft criou depois) → reaproveita.
        $pre = MicrosoftCalendarService::findEvent($token, $payload['subject'], $start, $end);
        if ($pre['found'] && data_get($pre['found'], 'onlineMeeting.joinUrl')) {
            $this->syncAttendees($token, $pre['found'], $payload['attendees']);
            return $this->success($meeting, $pre['found'], ['reused' => true]);
        }

        // 2) Cria (teto 25s: o proxy corta a requisição em ~60s). transactionId = idempotência no Graph.
        $ev = MicrosoftCalendarService::createEvent($token, $payload, 25);
        if ($ev) return $this->success($meeting, $ev);

        $err  = MicrosoftCalendarService::$lastError;
        $kind = MicrosoftHealth::classify($err);

        // 3) Timeout/5xx: a Microsoft pode ter criado mesmo sem responder — confere antes de dizer "falhou".
        $post = ['found' => null, 'ok' => $pre['ok']];
        if ($kind === 'microsoft_unavailable' && $pre['ok']) {
            $post = MicrosoftCalendarService::findEvent($token, $payload['subject'], $start, $end);
            if ($post['found'] && data_get($post['found'], 'onlineMeeting.joinUrl')) {
                return $this->success($meeting, $post['found'], ['recovered_after_error' => true]);
            }
        }

        $scopes = MicrosoftCalendarService::tokenScopes($token);
        return ['external_meeting_id' => null, 'join_url' => null, 'provider_data' => [
            'reason'  => 'graph_error',
            'kind'    => $kind,                               // microsoft_unavailable | auth | rejected
            // not_created: a Microsoft recusou; unknown: não respondeu e não deu p/ confirmar na agenda.
            'outcome' => ($kind === 'microsoft_unavailable' && (($err['status'] ?? 0) === 0 || !$post['ok'])) ? 'unknown' : 'not_created',
            'graph'   => MicrosoftHealth::sanitize($err),
            'attendees' => count($payload['attendees']),
            'missing_write' => $scopes !== null && !preg_match('/Calendars\.ReadWrite/i', $scopes),
            // A leitura da agenda respondeu? (consulta acima — somente leitura, nada é gravado)
            'probe'   => ['calendar_ok' => (bool) $pre['ok']],
        ]];
    }

    private function success(Meeting $meeting, array $ev, array $extra = []): array
    {
        return [
            'external_meeting_id' => $ev['id'] ?? null,
            'join_url'            => data_get($ev, 'onlineMeeting.joinUrl'),
            'provider_data'       => ['event_id' => $ev['id'] ?? null, 'web_link' => $ev['webLink'] ?? null, 'organizer' => $this->organizerEmail($meeting)] + $extra,
        ];
    }

    /** Reaproveitando um evento: inclui quem ainda não está convidado (best-effort, não bloqueia). */
    private function syncAttendees(string $token, array $ev, array $attendees): void
    {
        $have = collect(data_get($ev, 'attendees', []))->map(fn ($a) => mb_strtolower((string) data_get($a, 'emailAddress.address')))->all();
        $missing = collect($attendees)->reject(fn ($a) => in_array(mb_strtolower((string) data_get($a, 'emailAddress.address')), $have, true));
        if ($missing->isEmpty() || empty($ev['id'])) return;
        MicrosoftCalendarService::updateEvent($token, $ev['id'], ['attendees' => array_values(array_merge(data_get($ev, 'attendees', []), $missing->values()->all()))], 10);
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
    protected function tokenFor(Meeting $meeting): ?string
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
            // Idempotência: se uma tentativa estourar o tempo e o usuário tentar de novo, o Graph
            // devolve o MESMO evento em vez de criar outro (organizador + assunto + início).
            'transactionId' => 'minutor-' . substr(sha1(($this->actingUserId($meeting) ?? 0) . '|' . $meeting->title . '|' . Carbon::parse($meeting->starts_at)->toIso8601String()), 0, 32),
        ];
    }

    private function dt($carbon, ?string $tz): array
    {
        return ['dateTime' => Carbon::parse($carbon)->timezone($tz ?: 'America/Sao_Paulo')->format('Y-m-d\TH:i:s'), 'timeZone' => $tz ?: 'America/Sao_Paulo'];
    }
}
