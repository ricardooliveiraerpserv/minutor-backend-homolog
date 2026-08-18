<?php

namespace App\SourceCode\Cost;

use App\Models\SourceDoc;
use App\Models\SourceDocAiSettings;

/**
 * Central de Fontes — Frente A. Resolve a configuração de custo EFETIVA de uma fonte em cascata:
 * repo (mais específico) → customer → global → fallback ao config/services.php. Cada linha é um
 * conjunto COMPLETO (não merge por campo): a mais específica existente vence e vira a origem exibida.
 */
class CostSettingsResolver
{
    /** Defaults de segurança quando nem a linha global existe (ex.: DB recém-migrado sem seed). */
    private const FALLBACK_AUTO = 1.0000;
    private const FALLBACK_MARGIN = 10.00;
    private const FALLBACK_MAX_APPROVED = 3.0000;

    public function for(SourceDoc $doc): ResolvedCostSettings
    {
        $repoRow = ($doc->source_repo_id)
            ? SourceDocAiSettings::query()->where('scope_type', 'repo')->where('scope_id', $doc->source_repo_id)->first()
            : null;
        if ($repoRow) {
            return $this->fromRow($repoRow, 'repo', 'Repositório ' . ($doc->repository ?: ('#' . $doc->source_repo_id)));
        }

        $custRow = ($doc->customer_id)
            ? SourceDocAiSettings::query()->where('scope_type', 'customer')->where('scope_id', $doc->customer_id)->first()
            : null;
        if ($custRow) {
            $name = $doc->relationLoaded('customer') ? ($doc->customer?->name) : (optional($doc->customer)->name);
            return $this->fromRow($custRow, 'customer', 'Cliente ' . ($name ?: ('#' . $doc->customer_id)));
        }

        return $this->global();
    }

    /** Configuração global vigente (ou fallback ao config quando ausente). */
    public function global(): ResolvedCostSettings
    {
        $row = SourceDocAiSettings::query()->where('scope_type', 'global')->where('scope_id', 0)->first();
        if ($row) {
            return $this->fromRow($row, 'global', 'Configuração global');
        }
        // Fallback duro (mantém compatibilidade com o motor: max por passo vem do config atual).
        return new ResolvedCostSettings(
            automaticCostLimitUsd: self::FALLBACK_AUTO,
            safetyMarginPercent: self::FALLBACK_MARGIN,
            maxSemanticStepUsd: (float) config('services.source_doc_ai.hard_limit_usd', 0.30),
            approvalRequiredAboveLimit: true,
            maxApprovedCostUsd: self::FALLBACK_MAX_APPROVED,
            approvalMandatoryAboveUsd: null,
            source: 'config',
            sourceLabel: 'Configuração global (padrão do sistema)',
        );
    }

    private function fromRow(SourceDocAiSettings $row, string $source, string $label): ResolvedCostSettings
    {
        return new ResolvedCostSettings(
            automaticCostLimitUsd: (float) $row->automatic_cost_limit_usd,
            safetyMarginPercent: (float) $row->safety_margin_percent,
            maxSemanticStepUsd: (float) $row->max_semantic_step_usd,
            approvalRequiredAboveLimit: (bool) $row->approval_required_above_limit,
            maxApprovedCostUsd: (float) $row->max_approved_cost_usd,
            approvalMandatoryAboveUsd: $row->approval_mandatory_above_usd !== null ? (float) $row->approval_mandatory_above_usd : null,
            source: $source,
            sourceLabel: $label,
        );
    }
}
