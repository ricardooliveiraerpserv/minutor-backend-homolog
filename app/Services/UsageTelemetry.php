<?php

namespace App\Services;

use App\Models\UsageEvent;
use Illuminate\Support\Facades\Auth;

/**
 * Telemetria de uso — gravador EXCEPTION-SAFE de eventos de produto.
 *
 * Regra de ouro: telemetria NUNCA quebra a operação. Qualquer falha ao registrar é
 * engolida (a feature de negócio segue normalmente). Coleta apenas — sem dashboards
 * (Fase de Consolidação: "validar, medir, aprender"). Estruturado p/ a IA futura ler.
 */
class UsageTelemetry
{
    public function record(string $feature, string $action, array $opts = []): void
    {
        try {
            UsageEvent::create([
                'scope'           => $opts['scope'] ?? 'help_desk',
                'feature'         => $feature,
                'action'          => $action,
                'user_id'         => $opts['user_id'] ?? Auth::id(),
                'entity_type'     => $opts['entity_type'] ?? null,
                'entity_id'       => $opts['entity_id'] ?? null,
                'work_session_id' => $opts['work_session_id'] ?? null,
                'metadata'        => $opts['metadata'] ?? null,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // silencioso por contrato — nunca propaga
        }
    }
}
