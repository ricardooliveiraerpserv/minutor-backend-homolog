<?php

namespace App\Connector;

use App\Connector\BaseSeed\LiveBaseSeeder;
use App\Models\ConnectorEnvironmentState;
use App\Models\EnvEnvironment;

/**
 * CP-PREPHYSICAL — read-model de PRONTIDÃO FÍSICA (diagnóstico do porquê a física está bloqueada). SÓ LEITURA:
 * NUNCA habilita live. Separa explicitamente "contract supported" de "physical capability PROVEN". physical_ready
 * só é true quando TODOS os pré-requisitos de P0 estiverem satisfeitos (nunca nesta fase).
 */
class PhysicalReadinessService
{
    public function __construct(private LiveBaseSeeder $liveSeed)
    {
    }

    public function readiness(EnvEnvironment $env): array
    {
        $envId = (int) $env->id;
        $state = ConnectorEnvironmentState::where('environment_id', $envId)->first();

        // Lock integration = pronto EM SOFTWARE (Compile e Patch usam o mesmo WorkspaceLockService).
        $compileLockReady = true;
        $patchLockReady = true;

        // Base seed físico: só quando adapter TOTVS homologado (LiveBaseSeeder.availability). Hoje unavailable.
        $baseSeedReady = (bool) ($this->liveSeed->availability($envId)['available'] ?? false);

        // Capability PROVEN (não só declarada): exige live_ready + capability suportada. Fail-closed.
        $compileCapabilityReady = $this->capabilityProven('compile', $state?->compile_capability, 'source_compile');
        $patchCapabilityReady = $this->capabilityProven('patch', $state?->patch_capability, 'rpo_patch');

        // Conector presente (agente enrolado e não revogado) e workspace observado.
        $connectorReady = (bool) ($state && $state->agent_id && empty($state->revoked_at));
        $workspaceReady = $connectorReady && $this->hasObservedWorkspace($envId);

        $liveReady = (bool) config('connector.compile.live_ready', false) && (bool) config('connector.patch.live_ready', false);

        $flags = [
            'compile_lock_ready' => $compileLockReady,
            'patch_lock_ready' => $patchLockReady,
            'base_seed_ready' => $baseSeedReady,
            'compile_capability_ready' => $compileCapabilityReady,
            'patch_capability_ready' => $patchCapabilityReady,
            'connector_ready' => $connectorReady,
            'workspace_ready' => $workspaceReady,
            'live_ready' => $liveReady,
        ];
        $physicalReady = ! in_array(false, $flags, true);

        $reasons = [];
        foreach ($flags as $k => $v) { if (! $v) { $reasons[] = $this->reasonFor($k); } }

        return array_merge($flags, [
            'physical_ready' => $physicalReady,           // SEMPRE false nesta fase (por design)
            'blocking_reasons' => $reasons,
            'note' => 'Diagnóstico read-only. Não habilita execução física. P0 só após todos os pré-requisitos + revogação dos PATs comprometidos.',
        ]);
    }

    /** Capability physically PROVEN ≠ contract supported. Exige live_ready + declaração + versão suportada. */
    private function capabilityProven(string $domain, $cap, string $name): bool
    {
        if (! (bool) config("connector.{$domain}.live_ready", false)) { return false; }
        $cap = is_array($cap) ? $cap : null;
        if (! $cap || ($cap['name'] ?? null) !== $name) { return false; }
        return collect((array) config("connector.{$domain}.supported_capabilities", []))
            ->contains(fn ($c) => ($c['name'] ?? null) === $name && (int) ($c['contract_version'] ?? -1) === (int) ($cap['contract_version'] ?? -2));
    }

    private function hasObservedWorkspace(int $envId): bool
    {
        // Workspace observado via binding ativo (ENV-HUB) ou topologia (RPO-DISCOVERY), se as tabelas existirem.
        foreach (['connector_appserver_bindings' => ['status', 'active'], 'rpo_topology_observations' => null] as $table => $filter) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) { continue; }
            $q = \Illuminate\Support\Facades\DB::table($table)->where('environment_id', $envId);
            if ($filter) { $q->where($filter[0], $filter[1]); }
            if ($q->exists()) { return true; }
        }
        return false;
    }

    private function reasonFor(string $flag): array
    {
        $map = [
            'base_seed_ready' => ['code' => 'base_seed_unavailable', 'type' => 'REQUIRES_PHYSICAL_ENVIRONMENT'],
            'compile_capability_ready' => ['code' => 'compile_capability_unproven', 'type' => 'REQUIRES_PHYSICAL_ENVIRONMENT'],
            'patch_capability_ready' => ['code' => 'patch_capability_unproven', 'type' => 'REQUIRES_PHYSICAL_ENVIRONMENT'],
            'connector_ready' => ['code' => 'connector_absent', 'type' => 'REQUIRES_PHYSICAL_ENVIRONMENT'],
            'workspace_ready' => ['code' => 'workspace_not_observed', 'type' => 'REQUIRES_PHYSICAL_ENVIRONMENT'],
            'live_ready' => ['code' => 'live_disabled_by_design', 'type' => 'BLOCKED_BY_PHYSICAL_GATES'],
            'compile_lock_ready' => ['code' => 'compile_lock_not_integrated', 'type' => 'RESOLVED_IN_SOFTWARE'],
            'patch_lock_ready' => ['code' => 'patch_lock_not_integrated', 'type' => 'RESOLVED_IN_SOFTWARE'],
        ];
        return $map[$flag] ?? ['code' => $flag, 'type' => 'UNKNOWN'];
    }
}
