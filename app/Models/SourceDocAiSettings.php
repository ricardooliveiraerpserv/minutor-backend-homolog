<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Central de Fontes — Frente A. Configuração administrativa persistente dos limites de IA.
 * Resolução em cascata (global → customer → repo) feita pelo CostSettingsResolver. scope_id=0 = global.
 */
class SourceDocAiSettings extends Model
{
    protected $table = 'source_doc_ai_settings';

    public const SCOPES = ['global', 'customer', 'repo'];

    protected $fillable = [
        'scope_type', 'scope_id', 'automatic_cost_limit_usd', 'safety_margin_percent',
        'max_semantic_step_usd', 'approval_required_above_limit', 'max_approved_cost_usd',
        'approval_mandatory_above_usd', 'updated_by',
    ];

    protected $casts = [
        'automatic_cost_limit_usd' => 'decimal:4',
        'safety_margin_percent' => 'decimal:2',
        'max_semantic_step_usd' => 'decimal:4',
        'approval_required_above_limit' => 'boolean',
        'max_approved_cost_usd' => 'decimal:4',
        'approval_mandatory_above_usd' => 'decimal:4',
    ];

    /** Limite operacional = automático × (1 − margem%). Nunca gastar até o último centavo. */
    public function operationalLimit(): float
    {
        return round((float) $this->automatic_cost_limit_usd * (1 - (float) $this->safety_margin_percent / 100), 4);
    }
}
