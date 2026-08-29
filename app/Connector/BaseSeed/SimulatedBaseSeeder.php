<?php

namespace App\Connector\BaseSeed;

/**
 * CP-PREPHYSICAL — SimulatedBaseSeeder: prova a SEMÂNTICA do contrato (não a física). Recebe o digest observado
 * localmente por injeção controlada (SIMULADO) e devolve 'prepared' se == approved, senão 'base_mismatch'. Marca
 * simulated=true. NÃO representa preparação real de RPO; nenhuma evidência pode parecer física TOTVS.
 */
class SimulatedBaseSeeder implements BaseSeeder
{
    public function mode(): string { return 'simulated'; }

    public function availability(int $envId): array
    {
        $ok = in_array('simulated', (array) config('connector.base_seed.executable_modes', ['simulated']), true);
        return ['available' => $ok, 'reason' => $ok ? null : 'simulated_not_executable'];
    }

    /** $observedDigest é injetado (SIMULADO) — em live viria da prova física on-prem. */
    public function prepareBase(int $envId, string $workspaceUnitId, string $approvedBaseDigest, ?string $observedDigest = null): array
    {
        return $this->prove('prepared', $workspaceUnitId, $approvedBaseDigest, $observedDigest);
    }

    public function reseedBase(int $envId, string $workspaceUnitId, string $approvedBaseDigest, ?string $observedDigest = null): array
    {
        return $this->prove('reseeded', $workspaceUnitId, $approvedBaseDigest, $observedDigest);
    }

    private function prove(string $okResult, string $ws, string $approved, ?string $observed): array
    {
        $observed = $observed ?: $approved; // simulação default: workspace já no estado aprovado
        $match = preg_match('/^[0-9a-f]{64}$/i', $observed) && strtolower($observed) === strtolower($approved);
        return [
            'workspace_unit_id' => $ws, 'approved_digest' => strtolower($approved),
            'observed_local_digest' => strtolower($observed),
            'result' => $match ? $okResult : 'base_mismatch',
            'adapter_version' => 'simulated@1', 'simulated' => true,
        ];
    }
}
