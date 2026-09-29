<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Item da campanha = 1 source_doc. fase 1 = representante do blob (roda IA); fase 2 = réplica (reuso).
 */
class SourceSemanticCampaignItem extends Model
{
    protected $table = 'source_semantic_campaign_items';

    protected $fillable = [
        'campaign_id', 'source_doc_id', 'blob_sha', 'band', 'is_representative', 'phase',
        'status', 'execution_status', 'documentary_completeness', 'funcoes_missing',
        'estimated_cost_usd', 'cost_usd', 'attempts', 'last_error_kind', 'dispatched_at', 'finished_at',
    ];

    protected $casts = [
        'is_representative' => 'boolean',
        'estimated_cost_usd' => 'decimal:4', 'cost_usd' => 'decimal:4',
        'dispatched_at' => 'datetime', 'finished_at' => 'datetime',
    ];
}
