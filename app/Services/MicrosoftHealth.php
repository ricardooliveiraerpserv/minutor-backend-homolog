<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Saúde das integrações Microsoft 365 usadas pelo Minutor (Agenda, Teams, E-mail).
 * Cada chamada ao Graph registra sucesso/falha aqui; o card "Saúde da integração" lê o retrato.
 *
 * Estados: ok | degraded (última chamada falhou) | recovered (voltou a funcionar após falha nas
 * últimas 24h) | unknown (sem chamadas registradas). Erros são SANITIZADOS (sem token/URL) e
 * classificados: microsoft_unavailable (timeout/5xx/429), auth (401/403), rejected (outros 4xx).
 * Nunca lança — saúde é diagnóstico, não pode derrubar a operação.
 */
class MicrosoftHealth
{
    public const SERVICES = ['agenda', 'teams', 'email'];

    public static function record(string $service, bool $ok, ?array $error = null): void
    {
        try {
            $k = "ms_health:{$service}";
            $s = Cache::get($k, []);
            $now = now()->toIso8601String();
            if ($ok) {
                $s['last_ok_at'] = $now;
                $s['consecutive_failures'] = 0;
            } else {
                $s['last_fail_at'] = $now;
                $s['consecutive_failures'] = (int) ($s['consecutive_failures'] ?? 0) + 1;
                $s['last_error'] = self::sanitize($error);
            }
            Cache::forever($k, $s);
        } catch (\Throwable) {
        }
    }

    /** Só o necessário p/ diagnóstico: status, código, classe e mensagem curta sem tokens/URLs. */
    public static function sanitize(?array $e): ?array
    {
        if (!$e) return null;
        $msg = (string) ($e['message'] ?? '');
        $msg = preg_replace('/Bearer\s+\S+|eyJ[\w-]+\.[\w-]+\.[\w-]*/', '[oculto]', $msg);
        $msg = preg_replace('#\(see https?://\S+\)|https?://\S+#', '', $msg);
        return [
            'status'  => isset($e['status']) ? (int) $e['status'] : null,
            'code'    => $e['code'] ?? null,
            'kind'    => self::classify($e),
            'message' => mb_substr(trim((string) $msg), 0, 200),
        ];
    }

    public static function classify(?array $e): string
    {
        $s = (int) ($e['status'] ?? 0);
        if ($s === 0 || $s >= 500 || $s === 429) return 'microsoft_unavailable';
        if ($s === 401 || $s === 403) return 'auth';
        return 'rejected';
    }

    public static function state(array $s): string
    {
        $ok   = isset($s['last_ok_at']) ? Carbon::parse($s['last_ok_at']) : null;
        $fail = isset($s['last_fail_at']) ? Carbon::parse($s['last_fail_at']) : null;
        if (!$ok && !$fail) return 'unknown';
        if ($fail && (!$ok || $fail->gt($ok))) return 'degraded';
        if ($fail && $fail->gt(now()->subDay())) return 'recovered';
        return 'ok';
    }

    /** @return array<string, array> */
    public static function snapshot(array $services = self::SERVICES): array
    {
        $out = [];
        foreach ($services as $svc) {
            $s = Cache::get("ms_health:{$svc}", []);
            $out[$svc] = [
                'state'                => self::state($s),
                'last_ok_at'           => $s['last_ok_at'] ?? null,
                'last_fail_at'         => $s['last_fail_at'] ?? null,
                'consecutive_failures' => (int) ($s['consecutive_failures'] ?? 0),
                'last_error'           => $s['last_error'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Incidentes ATIVOS publicados pela Microsoft (Integridade do serviço) para os serviços que
     * importam ao Minutor. Exige permissão de APLICATIVO ServiceHealth.Read.All no app graph_reader
     * (com consentimento do admin) — sem ela devolve available=false + o motivo. Cache 5 min.
     */
    public static function incidents(): array
    {
        if (!GraphMailReader::enabled()) return ['available' => false, 'reason' => 'not_configured', 'items' => []];
        return Cache::remember('ms_health:incidents', now()->addMinutes(5), function () {
            try {
                $r = Http::withToken(GraphMailReader::appToken())->acceptJson()->connectTimeout(5)->timeout(10)
                    ->get('https://graph.microsoft.com/v1.0/admin/serviceAnnouncement/issues', [
                        '$filter' => 'isResolved eq false',
                        '$select' => 'id,title,service,status,classification,startDateTime,lastModifiedDateTime',
                        '$top'    => 50,
                    ]);
                if ($r->status() === 401 || $r->status() === 403) {
                    return ['available' => false, 'reason' => 'permission', 'items' => []];
                }
                if (!$r->successful()) return ['available' => false, 'reason' => 'error', 'status' => $r->status(), 'items' => []];
                $wanted = ['Exchange Online', 'Microsoft Teams', 'SharePoint Online', 'Microsoft 365 suite'];
                $items = collect($r->json('value', []))
                    ->filter(fn ($i) => in_array($i['service'] ?? '', $wanted, true))
                    ->map(fn ($i) => [
                        'id'             => $i['id'] ?? null,
                        'title'          => $i['title'] ?? '',
                        'service'        => $i['service'] ?? '',
                        'status'         => $i['status'] ?? '',
                        'classification' => $i['classification'] ?? '',
                        'start'          => $i['startDateTime'] ?? null,
                        'updated'        => $i['lastModifiedDateTime'] ?? null,
                    ])->sortByDesc('updated')->values()->all();
                return ['available' => true, 'items' => $items];
            } catch (\Throwable) {
                return ['available' => false, 'reason' => 'error', 'items' => []];
            }
        });
    }
}
