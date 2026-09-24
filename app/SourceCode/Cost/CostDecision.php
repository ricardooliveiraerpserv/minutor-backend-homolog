<?php

namespace App\SourceCode\Cost;

use App\Models\SourceDocCostApproval;

/**
 * Resultado da decisão do SourceCostGovernor antes de um passo pago.
 * outcome: allow (reservou, pode chamar) | needs_approval (fila) | deny_partial (encerra parcial).
 */
class CostDecision
{
    public function __construct(
        public readonly string $outcome,
        public readonly float $reservedUsd,
        public readonly float $actualCostUsd,
        public readonly float $operationalLimitUsd,
        public readonly float $estimatedNextUsd,
        public readonly ResolvedCostSettings $settings,
        public readonly ?SourceDocCostApproval $approval = null,
    ) {
    }

    public function allowed(): bool
    {
        return $this->outcome === 'allow';
    }

    public function needsApproval(): bool
    {
        return $this->outcome === 'needs_approval';
    }
}
