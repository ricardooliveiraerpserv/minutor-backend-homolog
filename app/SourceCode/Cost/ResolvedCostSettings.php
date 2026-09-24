<?php

namespace App\SourceCode\Cost;

/**
 * Configuração de custo EFETIVA já resolvida (cascata global→customer→repo) para uma fonte.
 * Carrega a ORIGEM (source + label) para a UI mostrar "Herdado de: Cliente PROMAX".
 */
class ResolvedCostSettings
{
    public function __construct(
        public readonly float $automaticCostLimitUsd,
        public readonly float $safetyMarginPercent,
        public readonly float $maxSemanticStepUsd,
        public readonly bool $approvalRequiredAboveLimit,
        public readonly float $maxApprovedCostUsd,
        public readonly ?float $approvalMandatoryAboveUsd,
        public readonly string $source,       // repo | customer | global | config
        public readonly string $sourceLabel,  // "Cliente PROMAX", "Configuração global"...
    ) {
    }

    /** Limite operacional = automático × (1 − margem%). Nunca gastar até o último centavo. */
    public function operationalLimit(): float
    {
        return round($this->automaticCostLimitUsd * (1 - $this->safetyMarginPercent / 100), 4);
    }

    /** Acima deste valor a aprovação é obrigatória (default = limite automático). */
    public function approvalThreshold(): float
    {
        return $this->approvalMandatoryAboveUsd ?? $this->automaticCostLimitUsd;
    }

    public function toArray(): array
    {
        return [
            'automatic_cost_limit_usd' => round($this->automaticCostLimitUsd, 4),
            'safety_margin_percent' => round($this->safetyMarginPercent, 2),
            'operational_limit_usd' => $this->operationalLimit(),
            'max_semantic_step_usd' => round($this->maxSemanticStepUsd, 4),
            'approval_required_above_limit' => $this->approvalRequiredAboveLimit,
            'max_approved_cost_usd' => round($this->maxApprovedCostUsd, 4),
            'approval_mandatory_above_usd' => $this->approvalMandatoryAboveUsd !== null ? round($this->approvalMandatoryAboveUsd, 4) : null,
            'approval_threshold_usd' => round($this->approvalThreshold(), 4),
            'source' => $this->source,
            'source_label' => $this->sourceLabel,
        ];
    }
}
