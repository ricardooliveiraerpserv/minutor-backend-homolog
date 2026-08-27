<?php

namespace App\Connector;

use App\Models\ConnectorAgent;
use App\Models\ConnectorEnvironmentState;
use App\Models\ConnectorEvent;
use App\Models\ConnectorRpoSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/* Connector-3 injeta APENAS a correlação causal (comando→inventário); o pipeline de inventário
 * (diff/eventos/RPO) permanece EXATAMENTE o C-2 homologado — sem segunda lógica de inventário. */

/**
 * Conector-2 — processa um INVENTÁRIO observado (read-only). Regras:
 *  - AUTORIDADE de frescor = received_at (backend). observed_at (agente) só ORDENA/diagnostica.
 *  - Anti-regressão: inventário com observed_at ANTERIOR ao já aplicado é DESCARTADO (reorder/atraso).
 *  - Diff vs estado corrente → connector_events SÓ em transições significativas (uptime-only NÃO gera).
 *  - RPO: snapshot SÓ quando o sha256 muda (mtime/size sem mudança de hash NÃO é novo RPO; hash igual
 *    após restart NÃO duplica).
 * Nunca persiste secret/path/INI/bytes de RPO — só o já-sanitizado que chega do controller.
 */
class ConnectorInventoryProcessor
{
    public function __construct(
        private ConnectorCommandService $commands,
        private ConnectorOperationService $operations,
    ) {
    }

    /** @return array{applied:bool, events:int, snapshots:int} */
    public function process(ConnectorAgent $agent, array $inv, Carbon $receivedAt): array
    {
        $observedAt = isset($inv['observed_at']) ? Carbon::createFromTimestamp((int) $inv['observed_at']) : $receivedAt;
        $newApps = collect($inv['appservers'] ?? [])->keyBy('ref');
        $newRest = collect($inv['rest'] ?? [])->keyBy('name');
        $newRpo = collect($inv['rpo'] ?? [])->keyBy('appserver_ref');

        return DB::transaction(function () use ($agent, $inv, $receivedAt, $observedAt, $newApps, $newRest, $newRpo) {
            $envId = (int) $agent->environment_id;
            $row = ConnectorEnvironmentState::where('environment_id', $envId)->lockForUpdate()->first();

            // Anti-regressão: dado mais ANTIGO (observed_at menor que o já aplicado) não regride o corrente.
            if ($row && $row->inventory_observed_at && $observedAt->lt($row->inventory_observed_at)) {
                return ['applied' => false, 'events' => 0, 'snapshots' => 0];
            }

            $old = $row?->observed_json ?? [];
            $oldApps = collect($old['appservers'] ?? [])->keyBy('ref');
            $oldRest = collect($old['rest'] ?? [])->keyBy('name');

            $events = 0; $snapshots = 0;
            $emit = function (string $type, ?string $ref, string $outcome, ?string $detail, array $meta = []) use ($envId, $receivedAt, &$events) {
                ConnectorEvent::create([
                    'environment_id' => $envId, 'appserver_ref' => $ref, 'event_type' => $type,
                    'outcome' => $outcome, 'detail' => $detail, 'meta' => $meta, 'occurred_at' => $receivedAt,
                ]);
                $events++;
            };

            // ── AppServers: apareceu/sumiu, up/down, versão/build (uptime-only NÃO gera) ──
            foreach ($newApps as $ref => $a) {
                $o = $oldApps->get($ref);
                if (! $o) {
                    $emit('appserver_up', $ref, 'ok', "AppServer {$a['name']} detectado", ['name' => $a['name'], 'up' => (bool) ($a['up'] ?? false)]);
                } else {
                    if ((bool) ($o['up'] ?? false) !== (bool) ($a['up'] ?? false)) {
                        $emit('process_changed', $ref, ($a['up'] ?? false) ? 'ok' : 'fail', "Processo {$a['name']} " . (($a['up'] ?? false) ? 'up' : 'down'), ['up' => (bool) ($a['up'] ?? false)]);
                    }
                    // version/build/patch AUSENTES são opcionais: NÃO derrubam a coleta e NÃO geram
                    // version_changed falso. Só emite quando há INFORMAÇÃO COMPARÁVEL SUFICIENTE — i.e.,
                    // o mesmo campo presente (não-null) nos DOIS lados e realmente diferente. Ausência
                    // ≠ mudança (não inventa valor, não persiste "unknown"). Interpolação null-safe.
                    $vbp = ['version', 'build', 'patch'];
                    $realDiff = false;
                    foreach ($vbp as $f) {
                        $ov = $o[$f] ?? null; $av = $a[$f] ?? null;
                        if ($ov !== null && $av !== null && $ov !== $av) { $realDiff = true; }
                    }
                    if ($realDiff) {
                        $fmt = fn ($x) => implode('·', array_map(fn ($f) => $x[$f] ?? '—', $vbp));
                        $emit('version_changed', $ref, 'info', "Versão de {$a['name']}", ['from' => $fmt($o), 'to' => $fmt($a)]);
                    }
                    // uptime muda a cada coleta e NÃO gera evento (ignorado de propósito).
                }
            }
            foreach ($oldApps as $ref => $o) {
                if (! $newApps->has($ref)) {
                    $emit('appserver_down', $ref, 'fail', "AppServer {$o['name']} sumiu", ['name' => $o['name']]);
                }
            }

            // ── REST: healthy↔unhealthy ──
            foreach ($newRest as $name => $r) {
                $o = $oldRest->get($name);
                if ($o !== null && (bool) ($o['healthy'] ?? false) !== (bool) ($r['healthy'] ?? false)) {
                    $emit('rest_health_changed', null, ($r['healthy'] ?? false) ? 'ok' : 'fail', "REST {$name} " . (($r['healthy'] ?? false) ? 'healthy' : 'unhealthy'), ['name' => $name, 'healthy' => (bool) ($r['healthy'] ?? false)]);
                }
            }

            // ── RPO: snapshot + evento SÓ quando o hash muda (dedup por par env+ref) ──
            foreach ($newRpo as $ref => $rpo) {
                $hash = $rpo['hash'] ?? null;
                if (! $hash) { continue; }
                $last = ConnectorRpoSnapshot::where('environment_id', $envId)->where('appserver_ref', $ref)
                    ->orderByDesc('observed_at')->orderByDesc('id')->first();
                if ($last && $last->rpo_hash === $hash) {
                    continue; // mesmo hash (mtime/size irrelevantes) → não é novo RPO, não duplica
                }
                ConnectorRpoSnapshot::create([
                    'environment_id' => $envId, 'appserver_ref' => $ref, 'rpo_hash' => $hash,
                    'rpo_version' => $rpo['version'] ?? null, 'size_bytes' => $rpo['size'] ?? null,
                    'mtime' => isset($rpo['mtime']) ? Carbon::createFromTimestamp((int) $rpo['mtime']) : null,
                    'observed_at' => $receivedAt,
                ]);
                $snapshots++;
                // Evento SÓ em MUDANÇA real (havia RPO anterior diferente). Primeira observação = baseline.
                if ($last) {
                    $emit('rpo_changed', $ref, 'info', 'RPO alterado', ['rpo_hash' => substr($hash, 0, 12), 'from' => substr($last->rpo_hash, 0, 12)]);
                }
            }

            // ── Estado corrente (sobrescrito). received_at = frescor; observed_at = diagnóstico/ordenação. ──
            $observedJson = [
                'appservers' => $newApps->values()->all(),
                'rest'       => $newRest->values()->all(),
                'rpo'        => $newRpo->values()->all(),
                'collect_error' => $inv['collect_error'] ?? null,
            ];
            // NÃO toca last_seen_at (presença C-1 é INDEPENDENTE do inventário C-2). Se a linha ainda
            // não existe (inventário antes do 1º heartbeat), last_seen_at fica NULL → presença never_seen.
            ConnectorEnvironmentState::updateOrCreate(
                ['environment_id' => $envId],
                [
                    'agent_id'              => $agent->agent_id,
                    'observed_json'         => $observedJson,
                    'inventory_received_at' => $receivedAt,
                    'inventory_observed_at' => $observedAt,
                ]
            );

            // Connector-3 — CORRELAÇÃO FORTE (aditivo): se este inventário foi disparado por um comando
            // (trigger.type=command), vincula ao comando SÓ se for do mesmo ambiente/agente e em voo.
            // Nunca por ordem temporal; command_id de outro ambiente/agente não correlaciona.
            $trigger = $inv['trigger'] ?? null;
            if (is_array($trigger) && ($trigger['type'] ?? null) === 'command' && ! empty($trigger['command_id'])) {
                $this->commands->markInventoryApplied($agent, (int) $trigger['command_id'], $receivedAt);
            }
            // C4.3 — coleta de reconciliação CORRELACIONADA a uma operação (restart): grava a autoridade
            // do desfecho (up/piid do alvo) na operação. Escopo/estado verificados no service.
            if (is_array($trigger) && ($trigger['type'] ?? null) === 'operation' && ! empty($trigger['operation_id'])) {
                $this->operations->recordReconcileObservation($agent, (int) $trigger['operation_id'], $newApps->all(), $receivedAt);
            }

            return ['applied' => true, 'events' => $events, 'snapshots' => $snapshots];
        });
    }
}
