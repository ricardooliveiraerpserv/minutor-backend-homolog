<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft 365 / Outlook — agenda do USUÁRIO via OAuth2 DELEGADO (authorization_code +
 * refresh_token). Cada usuário conecta a própria conta; o Minutor lê o calendário (Calendars.Read).
 * Independente do Graph app-only do Help Desk. Tudo defensivo: nunca lança p/ travar a UI.
 */
class MicrosoftCalendarService
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private static function cfg(): array
    {
        return config('services.microsoft_calendar', []);
    }

    /** true só quando client_id + client_secret + redirect_uri estão preenchidos (redirect_uri = interruptor). */
    public static function configured(): bool
    {
        $c = self::cfg();
        return !empty($c['client_id']) && !empty($c['client_secret']) && !empty($c['redirect_uri']);
    }

    private static function tokenUrl(): string
    {
        $tenant = self::cfg()['tenant_id'] ?: 'common';
        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
    }

    /** URL de consentimento (redireciona o usuário). $state amarra o callback ao usuário. */
    public static function authorizeUrl(string $state): string
    {
        $c = self::cfg();
        $tenant = $c['tenant_id'] ?: 'common';
        $params = http_build_query([
            'client_id'     => $c['client_id'],
            'response_type' => 'code',
            'redirect_uri'  => $c['redirect_uri'],
            'response_mode' => 'query',
            'scope'         => $c['scopes'],
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);
        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?{$params}";
    }

    /** Troca o authorization_code por tokens. Retorna array do Graph ou ['error'=>...]. */
    public static function exchangeCode(string $code): array
    {
        $c = self::cfg();
        $resp = Http::asForm()->timeout(10)->post(self::tokenUrl(), [
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $c['redirect_uri'],
            'scope'         => $c['scopes'],
        ]);
        return $resp->successful() ? $resp->json() : ['error' => $resp->json('error_description') ?: ('HTTP ' . $resp->status())];
    }

    /** Renova o access_token a partir do refresh_token. Retorna array do Graph ou ['error'=>...]. */
    public static function refresh(string $refreshToken): array
    {
        $c = self::cfg();
        $resp = Http::asForm()->timeout(10)->post(self::tokenUrl(), [
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'redirect_uri'  => $c['redirect_uri'],
            'scope'         => $c['scopes'],
        ]);
        return $resp->successful() ? $resp->json() : ['error' => $resp->json('error_description') ?: ('HTTP ' . $resp->status())];
    }

    /**
     * Garante um access_token válido p/ uma integração, renovando via refresh_token se expirado e
     * persistindo o novo. Fonte ÚNICA de renovação (usada pela Agenda e pela Central de Reuniões).
     */
    public static function freshTokenFor(\App\Models\UserIntegration $i): ?string
    {
        if (!$i->isExpired() && $i->access_token) return $i->access_token;
        if (!$i->refresh_token) return null;

        $tok = self::refresh($i->refresh_token);
        if (!empty($tok['error']) || empty($tok['access_token'])) return null;

        $i->update([
            'access_token'  => $tok['access_token'],
            'refresh_token' => $tok['refresh_token'] ?? $i->refresh_token, // MS pode rotacionar
            'expires_at'    => now()->addSeconds((int) ($tok['expires_in'] ?? 3600)),
        ]);
        return $tok['access_token'];
    }

    /**
     * Cria um EVENTO no calendário do usuário (delegado /me/events). Com isOnlineMeeting=true +
     * teamsForBusiness, o Graph devolve o joinUrl do Teams. Retorna o evento (array) ou null.
     */
    public static function createEvent(string $accessToken, array $payload): ?array
    {
        try {
            $r = Http::withToken($accessToken)->timeout(8)->acceptJson()->post(self::GRAPH_BASE . '/me/events', $payload);
            if ($r->successful()) return $r->json();
            \Illuminate\Support\Facades\Log::warning('📅 [MS-CAL] createEvent falhou', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('📅 [MS-CAL] createEvent exceção', ['error' => $e->getMessage()]);
        }
        return null;
    }

    /** Atualiza (PATCH) um evento delegado. Best effort. */
    public static function updateEvent(string $accessToken, string $eventId, array $patch): bool
    {
        try {
            return Http::withToken($accessToken)->timeout(10)->acceptJson()->patch(self::GRAPH_BASE . "/me/events/{$eventId}", $patch)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Remove um evento delegado (cancela a reunião). Best effort. */
    public static function deleteEvent(string $accessToken, string $eventId): bool
    {
        try {
            return Http::withToken($accessToken)->timeout(10)->delete(self::GRAPH_BASE . "/me/events/{$eventId}")->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Agenda detalhada do usuário no intervalo — p/ a Central de Reuniões (start/end ISO + link do
     * Teams quando for online meeting). Sempre array (vazio em falha; nunca trava a UI).
     * @return array<int,array{id:string,subject:string,starts_at:?string,ends_at:?string,is_all_day:bool,is_online:bool,join_url:?string}>
     */
    public static function fetchAgenda(string $accessToken, Carbon $start, Carbon $end): array
    {
        try {
            $url = self::GRAPH_BASE . '/me/calendarView?' . http_build_query([
                'startDateTime' => $start->toIso8601String(),
                'endDateTime'   => $end->toIso8601String(),
                '$select'       => 'id,subject,start,end,isAllDay,isOnlineMeeting,onlineMeeting,location,organizer,attendees,webLink,bodyPreview',
                '$orderby'      => 'start/dateTime',
                '$top'          => 200,
            ]);
            $r = Http::withToken($accessToken)->timeout(8)->acceptJson()
                ->withHeaders(['Prefer' => 'outlook.timezone="America/Sao_Paulo"'])
                ->get($url);
            if (!$r->successful()) return [];

            return collect($r->json('value', []))->map(function ($e) {
                $startDt = data_get($e, 'start.dateTime');
                $endDt   = data_get($e, 'end.dateTime');
                $attendees = collect(data_get($e, 'attendees', []))
                    ->map(fn ($a) => data_get($a, 'emailAddress.name') ?: data_get($a, 'emailAddress.address'))
                    ->filter()->take(20)->values()->all();
                return [
                    'id'         => (string) (data_get($e, 'id') ?: ''),
                    'subject'    => (string) (data_get($e, 'subject') ?: 'Compromisso'),
                    'starts_at'  => $startDt ? Carbon::parse($startDt, 'America/Sao_Paulo')->toIso8601String() : null,
                    'ends_at'    => $endDt ? Carbon::parse($endDt, 'America/Sao_Paulo')->toIso8601String() : null,
                    'is_all_day' => (bool) data_get($e, 'isAllDay'),
                    'is_online'  => (bool) data_get($e, 'isOnlineMeeting'),
                    'join_url'   => data_get($e, 'onlineMeeting.joinUrl'),
                    'location'   => data_get($e, 'location.displayName') ?: null,
                    'organizer'  => data_get($e, 'organizer.emailAddress.name') ?: null,
                    'attendees'  => $attendees,
                    'web_link'   => data_get($e, 'webLink') ?: null,
                    'preview'    => trim((string) data_get($e, 'bodyPreview')) ?: null,
                ];
            })->filter(fn ($e) => $e['starts_at'] !== null)->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Perfil básico (e-mail da conta conectada) — best effort. */
    public static function me(string $accessToken): ?string
    {
        try {
            $r = Http::withToken($accessToken)->timeout(8)->acceptJson()->get(self::GRAPH_BASE . '/me?$select=mail,userPrincipalName');
            if ($r->successful()) return $r->json('mail') ?: $r->json('userPrincipalName');
        } catch (\Throwable) {}
        return null;
    }

    /**
     * Eventos do calendário no intervalo [$start,$end]. Retorna [{tipo:'outlook', data, titulo, hora}],
     * sempre array (vazio em qualquer falha — não trava a UI).
     */
    public static function fetchEvents(string $accessToken, Carbon $start, Carbon $end): array
    {
        try {
            $url = self::GRAPH_BASE . '/me/calendarView?' . http_build_query([
                'startDateTime' => $start->toIso8601String(),
                'endDateTime'   => $end->toIso8601String(),
                '$select'       => 'subject,start,end,isAllDay,location,onlineMeeting,webLink,attendees,organizer',
                '$orderby'      => 'start/dateTime',
                '$top'          => 100,
            ]);
            $r = Http::withToken($accessToken)->timeout(8)->acceptJson()
                ->withHeaders(['Prefer' => 'outlook.timezone="America/Sao_Paulo"'])
                ->get($url);
            if (!$r->successful()) return [];

            return collect($r->json('value', []))->map(function ($e) {
                $startDt = data_get($e, 'start.dateTime');
                $endDt   = data_get($e, 'end.dateTime');
                $date = $startDt ? Carbon::parse($startDt)->toDateString() : null;
                if (!$date) return null;
                $convidados = collect(data_get($e, 'attendees', []))->map(fn ($a) => [
                    'nome'     => (string) (data_get($a, 'emailAddress.name') ?: data_get($a, 'emailAddress.address') ?: ''),
                    'email'    => (string) data_get($a, 'emailAddress.address'),
                    'resposta' => (string) (data_get($a, 'status.response') ?: 'none'), // accepted|declined|tentativelyAccepted|notResponded|none
                ])->filter(fn ($a) => $a['nome'] !== '')->values()->all();
                return [
                    'tipo'        => 'outlook',
                    'data'        => $date,
                    'titulo'      => (string) (data_get($e, 'subject') ?: 'Compromisso'),
                    'hora'        => data_get($e, 'isAllDay') ? null : ($startDt ? Carbon::parse($startDt)->format('H:i') : null),
                    'hora_fim'    => data_get($e, 'isAllDay') ? null : ($endDt ? Carbon::parse($endDt)->format('H:i') : null),
                    'local'       => (string) (data_get($e, 'location.displayName') ?: ''),
                    'link'        => (string) (data_get($e, 'onlineMeeting.joinUrl') ?: data_get($e, 'webLink') ?: ''),
                    'organizador' => (string) (data_get($e, 'organizer.emailAddress.name') ?: ''),
                    'convidados'  => $convidados,
                ];
            })->filter()->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Re-sincroniza a agenda do usuário e grava o snapshot em cached_events + last_sync_at.
     * Janela = início do mês atual → fim de +2 meses (cobre navegação p/ meses à frente).
     * Retorna o nº de eventos, ou null se não foi possível renovar o acesso. Defensivo.
     */
    public static function syncEvents(\App\Models\UserIntegration $i): ?int
    {
        $token = self::freshTokenFor($i);
        if (!$token) return null;

        $events = self::fetchEvents($token, now()->startOfMonth(), now()->addMonths(2)->endOfMonth());
        $i->update(['cached_events' => $events, 'last_sync_at' => now()]);

        return count($events);
    }
}
