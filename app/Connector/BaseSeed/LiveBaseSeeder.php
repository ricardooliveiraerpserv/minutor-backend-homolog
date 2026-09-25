<?php

namespace App\Connector\BaseSeed;

/**
 * CP-PREPHYSICAL — LiveBaseSeeder: CONTRATO pronto, mecanismo TOTVS PENDENTE. Enquanto não houver adapter físico
 * homologado E base_seed live não for explicitamente habilitado, availability() = unavailable e prepare/reseed
 * devolvem 'unavailable'. NENHUM fake success, NENHUM fallback. Habilitação futura só via gates CP-PHYSICAL.
 */
class LiveBaseSeeder implements BaseSeeder
{
    public function mode(): string { return 'live'; }

    public function availability(int $envId): array
    {
        // Default false → unavailable. Só muda após validação física comprovada (fora desta fase).
        if (! (bool) config('connector.base_seed.live_ready', false)) {
            return ['available' => false, 'reason' => 'base_seed_unavailable'];
        }
        return ['available' => false, 'reason' => 'base_seed_adapter_absent']; // sem adapter TOTVS homologado
    }

    public function prepareBase(int $envId, string $workspaceUnitId, string $approvedBaseDigest): array
    {
        return $this->unavailable($workspaceUnitId, $approvedBaseDigest);
    }

    public function reseedBase(int $envId, string $workspaceUnitId, string $approvedBaseDigest): array
    {
        return $this->unavailable($workspaceUnitId, $approvedBaseDigest);
    }

    private function unavailable(string $ws, string $approved): array
    {
        return ['workspace_unit_id' => $ws, 'approved_digest' => $approved, 'observed_local_digest' => null,
            'result' => 'unavailable', 'adapter_version' => 'live@pending', 'simulated' => false];
    }
}
