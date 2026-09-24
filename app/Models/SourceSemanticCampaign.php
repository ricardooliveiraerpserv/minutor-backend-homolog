<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campanha de Documentação Semântica Inicial (baseline one-shot; NÃO é o fluxo de GMUD).
 * Orçamento GLOBAL com LEDGER de reserva atômica (actual + reserved <= operational_limit).
 */
class SourceSemanticCampaign extends Model
{
    protected $table = 'source_semantic_campaign';

    public const STATUSES = ['draft', 'estimating', 'ready', 'running', 'paused', 'completed', 'completed_with_errors', 'cancelled'];

    protected $fillable = [
        'name', 'status', 'pause_reason', 'budget_usd', 'safety_margin', 'estimated_total_usd',
        'actual_cost_usd', 'reserved_cost_usd', 'max_concurrent', 'batch_size',
        'total_candidates', 'unique_blobs', 'processed', 'reused', 'completed', 'partial',
        'skipped', 'failed', 'cost_limit_skipped', 'giants_excluded', 'created_by',
        'started_at', 'paused_at', 'completed_at',
    ];

    protected $casts = [
        'budget_usd' => 'decimal:4', 'safety_margin' => 'decimal:4', 'estimated_total_usd' => 'decimal:4',
        'actual_cost_usd' => 'decimal:4', 'reserved_cost_usd' => 'decimal:4',
        'started_at' => 'datetime', 'paused_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SourceSemanticCampaignItem::class, 'campaign_id');
    }

    /** Limite operacional = budget × (1 − margem de segurança). Nunca gastar até o último centavo. */
    public function operationalLimit(): float
    {
        return (float) $this->budget_usd * (1 - (float) $this->safety_margin);
    }

    /** Orçamento disponível considerando o JÁ gasto + o RESERVADO (jobs em voo). */
    public function availableBudget(): float
    {
        return $this->operationalLimit() - (float) $this->actual_cost_usd - (float) $this->reserved_cost_usd;
    }
}
