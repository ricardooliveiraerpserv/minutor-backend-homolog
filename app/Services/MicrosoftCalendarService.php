<?php

namespace App\Services;

use App\Models\UserIntegration;
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

    /** true só quando client_id + client_secret estão preenchidos. */
    public static function configured(): bool
    {
        $c = self::cfg();
        return !empty($c['client_id']) && !empty($c['client_secret']);
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
        $resp = Http::asForm()->post(self::tokenUrl(), [
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
        $resp = Http::asForm()->post(self::tokenUrl(), [
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'redirect_uri'  => $c['redirect_uri'],
            'scope'         => $c['scopes'],
        ]);
        return $resp->successful() ? $resp->json() : ['error' => $resp->json('error_description') ?: ('HTTP ' . $resp->status())];
    }

    /** Perfil básico (e-mail da conta conectada) — best effort. */
    public static function me(string $accessToken): ?string
    {
        try {
            $r = Http::withToken($accessToken)->acceptJson()->get(self::GRAPH_BASE . '/me?$select=mail,userPrincipalName');
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
            $r = Http::withToken($accessToken)->acceptJson()
                ->timeout(12) // nunca segura a agenda por causa de um Graph lento
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
     * Garante um access_token válido p/ a integração (renova via refresh_token se expirado e persiste).
     * Fonte ÚNICA de renovação de token — usada pelo /sync e pelo auto-sync da agenda. null se não deu.
     */
    public static function freshTokenFor(UserIntegration $i): ?string
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
     * Re-sincroniza a agenda do usuário e grava o snapshot em cached_events + last_sync_at.
     * Janela = início do mês atual → fim de +2 meses (cobre navegação p/ meses à frente).
     * Retorna o nº de eventos, ou null se não foi possível renovar o acesso. Defensivo.
     */
    public static function syncEvents(UserIntegration $i): ?int
    {
        $token = self::freshTokenFor($i);
        if (!$token) return null;

        $events = self::fetchEvents($token, now()->startOfMonth(), now()->addMonths(2)->endOfMonth());
        $i->update(['cached_events' => $events, 'last_sync_at' => now()]);

        return count($events);
    }
}
