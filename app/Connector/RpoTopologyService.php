<?php

namespace App\Connector;

use App\Models\ConnectorEnvironmentState;
use App\Models\EnvEnvironment;
use App\Models\RpoTarget;
use App\Models\RpoTargetAppserver;
use App\Models\RpoTopologyObservation;

/**
 * RPO-DISCOVERY (C5.0) D2 — deriva SUGESTÕES de target a partir da topologia observada e faz a CONFIRMAÇÃO
 * governada (stale-safe) delegando ao C5.1 (createTarget+confirm) — a AUTORIDADE. NÃO altera C5. Observação
 * ≠ capability (capability vem de rpo_capability, fonte SEPARADA). NUNCA auto-altera target confirmado:
 * divergência é projeção advisory. Agrupamento por publish_unit_id (identidade opaca já existente).
 */
class RpoTopologyService
{
    public function __construct(private RpoRegistryService $rpo)
    {
    }

    public function latest(int $envId): ?RpoTopologyObservation
    {
        return RpoTopologyObservation::where('environment_id', $envId)->orderByDesc('topology_revision')->first();
    }

    /** Freshness pela AUTORIDADE server-side (backend_received_at), nunca pelo relógio do host. */
    public function isFresh(?RpoTopologyObservation $obs): bool
    {
        if (! $obs) { return false; }
        $ttl = (int) config('connector.operations.observed_freshness', 120);
        return $obs->backend_received_at && $obs->backend_received_at->getTimestamp() >= (now()->getTimestamp() - $ttl);
    }

    /** Capability EXECUTÁVEL (fonte SEPARADA da observação). */
    public function capability(int $envId): ?array
    {
        $cap = ConnectorEnvironmentState::where('environment_id', $envId)->first()?->rpo_capability;
        return is_array($cap) ? $cap : null;
    }

    /**
     * Sugestões (environment_name + publish_unit_id) → grupo candidato. Read-only, nenhuma alteração.
     * Estados: suggested_new | already_targeted | conflict. Constraints do contrato D1.
     */
    public function suggestions(int $envId): array
    {
        $obs = $this->latest($envId);
        if (! $obs || ! $this->isFresh($obs)) {
            return ['fresh' => false, 'suggestions' => []];
        }
        // Agrupa por (environment_name | publish_unit_id); membro SEM publish_unit_id NÃO é agrupável.
        $groups = [];
        foreach ((array) $obs->members as $m) {
            $pu = $m['publish_unit_id'] ?? null;
            if (! $pu) { continue; }
            $key = ($m['environment_name'] ?? '') . '|' . $pu;
            $groups[$key]['environment_name'] = $m['environment_name'] ?? null;
            $groups[$key]['publish_unit_id'] = $pu;
            $groups[$key]['members'][] = ['appserver_ref' => $m['appserver_ref'], 'role' => $m['role'] ?? 'unknown', 'role_source' => $m['role_source'] ?? 'unknown', 'up' => $m['up'] ?? false, 'rpo_hash' => $m['rpo_hash'] ?? null];
        }
        // Associação existente: 1 target ativo por ref/ambiente (unicidade C5).
        $byRef = RpoTargetAppserver::where('environment_id', $envId)->get()->keyBy('appserver_ref');

        $out = [];
        foreach ($groups as $g) {
            $refs = array_values(array_unique(array_map(fn ($x) => $x['appserver_ref'], $g['members'])));
            sort($refs);
            $targetIds = [];
            foreach ($refs as $r) {
                $row = $byRef->get($r);
                if ($row) { $targetIds[$r] = (int) $row->rpo_target_id; }
            }
            $distinct = array_values(array_unique(array_values($targetIds)));
            if (count($targetIds) === 0) {
                $state = 'suggested_new'; $existingTargetId = null;
            } elseif (count($targetIds) === count($refs) && count($distinct) === 1) {
                $state = 'already_targeted'; $existingTargetId = $distinct[0];
            } else {
                $state = 'conflict'; $existingTargetId = null; // parcial/split → nunca sugerir criação automática
            }
            $out[] = [
                'environment_name' => $g['environment_name'],
                'publish_unit_id' => $g['publish_unit_id'],
                'member_refs' => $refs,
                'members' => $g['members'],
                'state' => $state,
                'existing_target_id' => $existingTargetId,
                'suggested_name' => 'RPO ' . mb_substr(($g['environment_name'] ? $g['environment_name'] . ' / ' : '') . $g['publish_unit_id'], 0, 110),
            ];
        }
        return ['fresh' => true, 'observation_id' => $obs->observation_id, 'topology_revision' => $obs->topology_revision, 'topology_fingerprint' => $obs->topology_fingerprint, 'suggestions' => $out];
    }

    /**
     * Confirmação STALE-SAFE: revalida revision+fingerprint e o grupo contra a observação ATUAL. Diverge →
     * 409 topology_observation_stale (não confirma nova realidade). OK → delega ao C5.1 (createTarget+confirm).
     */
    public function confirm(EnvEnvironment $env, array $p, int $userId): array
    {
        $obs = $this->latest((int) $env->id);
        if (! $obs || ! $this->isFresh($obs)) {
            return ['ok' => false, 'error' => 'topology_not_available', 'status' => 409];
        }
        // Revisão/fingerprint que o usuário VIU precisam bater com a observação atual.
        if ((int) ($p['topology_revision'] ?? -1) !== (int) $obs->topology_revision
            || (string) ($p['topology_fingerprint'] ?? '') !== (string) $obs->topology_fingerprint) {
            return ['ok' => false, 'error' => 'topology_observation_stale', 'status' => 409, 'current_revision' => $obs->topology_revision];
        }
        $pu = (string) ($p['publish_unit_id'] ?? '');
        $wanted = array_values(array_unique(array_map('strval', (array) ($p['member_refs'] ?? []))));
        sort($wanted);
        if ($pu === '' || ! $wanted) {
            return ['ok' => false, 'error' => 'invalid_group', 'status' => 422];
        }
        // Grupo ATUAL para essa unidade, recomputado da observação (não confia no cliente).
        $current = [];
        foreach ((array) $obs->members as $m) {
            if (($m['publish_unit_id'] ?? null) === $pu) { $current[] = (string) $m['appserver_ref']; }
        }
        $current = array_values(array_unique($current));
        sort($current);
        if ($current !== $wanted) {
            return ['ok' => false, 'error' => 'topology_observation_stale', 'status' => 409, 'current_members' => $current];
        }
        // Autoridade C5.1 — cria e confirma o target (zero mudança em C5).
        $name = isset($p['name']) && trim((string) $p['name']) !== '' ? mb_substr((string) $p['name'], 0, 120) : ('RPO ' . mb_substr($pu, 0, 110));
        $created = $this->rpo->createTarget((int) $env->id, $env->customer_id, $name, $wanted, $userId);
        if (! ($created['ok'] ?? false)) {
            return ['ok' => false, 'error' => $created['error'] ?? 'create_failed', 'status' => 422];
        }
        $confirmed = $this->rpo->confirmTarget($created['target'], $userId);
        if (! ($confirmed['ok'] ?? false)) {
            return ['ok' => false, 'error' => $confirmed['error'] ?? 'confirm_failed', 'status' => 422, 'consistency' => $confirmed['consistency'] ?? null, 'target_id' => $created['target']->id];
        }
        return ['ok' => true, 'target' => $confirmed['target'], 'topology_revision' => $obs->topology_revision];
    }

    /**
     * Divergência pós-confirmação (advisory): target confirmado cujos membros não batem mais com o grupo
     * observado da sua unidade. NUNCA altera membership (sem syncTargetMembership). Só sinaliza.
     */
    public function divergences(int $envId): array
    {
        $obs = $this->latest($envId);
        if (! $obs) { return []; }
        // ref → publish_unit_id observado; publish_unit_id → refs observados.
        $refUnit = []; $unitRefs = [];
        foreach ((array) $obs->members as $m) {
            $ref = (string) $m['appserver_ref']; $pu = $m['publish_unit_id'] ?? null;
            $refUnit[$ref] = $pu;
            if ($pu) { $unitRefs[$pu][] = $ref; }
        }
        $out = [];
        $targets = RpoTarget::where('environment_id', $envId)->where('status', 'confirmed')->get();
        foreach ($targets as $t) {
            $confirmedRefs = RpoTargetAppserver::where('rpo_target_id', $t->id)->pluck('appserver_ref')->map('strval')->all();
            sort($confirmedRefs);
            if (! $confirmedRefs) { continue; }
            $units = array_values(array_unique(array_map(fn ($r) => $refUnit[$r] ?? null, $confirmedRefs)));
            if (count($units) !== 1 || $units[0] === null) {
                $out[] = ['target_id' => $t->id, 'name' => $t->name, 'reason' => 'unit_inconsistent', 'confirmed_refs' => $confirmedRefs, 'observed_refs' => null];
                continue;
            }
            $observed = array_values(array_unique($unitRefs[$units[0]] ?? []));
            sort($observed);
            if ($observed !== $confirmedRefs) {
                $out[] = ['target_id' => $t->id, 'name' => $t->name, 'reason' => 'membership_changed', 'confirmed_refs' => $confirmedRefs, 'observed_refs' => $observed];
            }
        }
        return $out;
    }

    /** Projeção read-model completa da tela "Integração RPO". */
    public function view(int $envId): array
    {
        $obs = $this->latest($envId);
        return [
            'observation' => $obs ? [
                'observation_id' => $obs->observation_id,
                'topology_revision' => $obs->topology_revision,
                'topology_fingerprint' => $obs->topology_fingerprint,
                'agent_observed_at' => optional($obs->agent_observed_at)->toIso8601String(),
                'backend_received_at' => optional($obs->backend_received_at)->toIso8601String(),
                'fresh' => $this->isFresh($obs),
                'members' => $obs->members,
            ] : null,
            'suggestions' => $this->suggestions($envId)['suggestions'],
            'divergences' => $this->divergences($envId),
            'capability' => $this->capability($envId), // fonte SEPARADA (activation_mode/restart_strategy)
        ];
    }
}
