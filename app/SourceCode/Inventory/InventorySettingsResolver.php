<?php

namespace App\SourceCode\Inventory;

use App\Models\SourceDocInventorySettings;

/**
 * Fase B — resolve a ALLOWLIST de extensões elegíveis para o inventário, por CAMPO e por escopo,
 * INDEPENDENTE do custo (não usa CostSettingsResolver). Precedência: repo → customer → global-row →
 * system_default (config('services.source_doc.inventory_extensions')). NULL = herda; [] = override explícito.
 * A origem é distinta para auditoria: repo | customer | global | system_default.
 */
class InventorySettingsResolver
{
    /**
     * @return array{extensions: string[], origin: string} origin ∈ repo|customer|global|system_default
     */
    public function resolve(?int $customerId, ?int $sourceRepoId): array
    {
        if ($sourceRepoId) {
            $row = $this->ownRow('repo', $sourceRepoId);
            if ($row && $row->inventory_extensions !== null) {
                return ['extensions' => $this->norm($row->inventory_extensions), 'origin' => 'repo'];
            }
        }
        if ($customerId) {
            $row = $this->ownRow('customer', $customerId);
            if ($row && $row->inventory_extensions !== null) {
                return ['extensions' => $this->norm($row->inventory_extensions), 'origin' => 'customer'];
            }
        }
        $g = $this->ownRow('global', 0);
        if ($g && $g->inventory_extensions !== null) {
            return ['extensions' => $this->norm($g->inventory_extensions), 'origin' => 'global'];
        }
        return ['extensions' => $this->defaultExtensions(), 'origin' => 'system_default'];
    }

    /** Existe override PRÓPRIO neste nível exato? (linha do escopo; inventory_extensions pode ser NULL) */
    public function ownRow(string $scopeType, int $scopeId): ?SourceDocInventorySettings
    {
        return SourceDocInventorySettings::query()
            ->where('scope_type', $scopeType)->where('scope_id', $scopeId)->first();
    }

    /** Allowlist global do sistema (config atual) — origem 'system_default'. */
    public function defaultExtensions(): array
    {
        return $this->norm((array) config('services.source_doc.inventory_extensions', ['prw', 'prx', 'prg', 'tlpp', 'tlp', 'aph']));
    }

    /**
     * Normaliza uma lista de extensões: lowercase, sem ponto inicial, sem duplicatas, só válidas ([a-z0-9]).
     * PRESERVA a lista vazia (não vira default) — [] é override explícito "nenhuma extensão".
     * @param  array<int,mixed>  $exts
     * @return string[]
     */
    public function norm(array $exts): array
    {
        $out = [];
        foreach ($exts as $e) {
            if (! is_string($e)) {
                continue;
            }
            $x = ltrim(strtolower(trim($e)), '.');
            if ($x === '' || ! preg_match('/^[a-z0-9]{1,10}$/', $x)) {
                continue;
            }
            $out[$x] = true;
        }
        return array_keys($out);
    }
}
