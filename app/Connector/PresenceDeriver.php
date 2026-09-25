<?php

namespace App\Connector;

use Illuminate\Support\Carbon;

/**
 * Conector-1 — deriva o STATUS de presença EXCLUSIVAMENTE server-side, a partir de last_seen_at
 * (= received_at). O agente NUNCA é autoridade do próprio estado. observed_at não entra aqui
 * (só diagnóstico via clock_offset). Thresholds em config/connector.php.
 *
 *   never_seen : agente enrolado, sem heartbeat ainda
 *   online     : Δ ≤ presence_online (75s)
 *   stale      : presence_online < Δ ≤ presence_offline (75s–300s)
 *   offline    : Δ > presence_offline (>300s)
 *   degraded   : online por tempo, mas reportando erro OU clock_offset > warn
 */
class PresenceDeriver
{
    /**
     * @return array{status:string, since_s:?int}
     */
    public function derive(?Carbon $lastSeenAt, ?string $reportedStatus, ?int $clockOffsetS, ?string $lastError, ?Carbon $now = null): array
    {
        if (! $lastSeenAt) {
            return ['status' => 'never_seen', 'since_s' => null];
        }
        $now ??= Carbon::now();
        $delta = max(0, $now->getTimestamp() - $lastSeenAt->getTimestamp());

        $online = (int) config('connector.presence_online', 75);
        $offline = (int) config('connector.presence_offline', 300);
        $offsetWarn = (int) config('connector.clock_offset_warn', 120);

        if ($delta > $offline) {
            return ['status' => 'offline', 'since_s' => $delta];
        }
        if ($delta > $online) {
            return ['status' => 'stale', 'since_s' => $delta];
        }
        // Online por tempo — degraded só aqui.
        $degraded = $reportedStatus === 'error'
            || ($lastError !== null && $lastError !== '')
            || ($clockOffsetS !== null && abs($clockOffsetS) > $offsetWarn);

        return ['status' => $degraded ? 'degraded' : 'online', 'since_s' => $delta];
    }
}
