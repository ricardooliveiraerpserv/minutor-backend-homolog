<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central de Fontes — Frente A. Solicitação de aprovação de custo de IA (fila "Aprovações de IA").
 * Criada pelo governor quando o próximo passo estouraria o limite operacional por fonte.
 */
class SourceDocCostApproval extends Model
{
    protected $table = 'source_doc_cost_approvals';

    public const STATUSES = ['pending', 'approved_step', 'approved_limit', 'closed_partial', 'rejected'];
    public const OPEN = 'pending';

    protected $fillable = [
        'source_doc_id', 'version_id', 'status', 'actual_cost_usd', 'authorized_limit_usd',
        'next_step', 'estimated_next_usd', 'new_limit_usd', 'reason', 'completeness_level',
        'gaps_json', 'recommendation', 'requested_by', 'decided_by', 'decided_at', 'idempotency_key',
    ];

    protected $casts = [
        'actual_cost_usd' => 'decimal:4',
        'authorized_limit_usd' => 'decimal:4',
        'estimated_next_usd' => 'decimal:4',
        'new_limit_usd' => 'decimal:4',
        'gaps_json' => 'array',
        'decided_at' => 'datetime',
    ];

    public function sourceDoc(): BelongsTo
    {
        return $this->belongsTo(SourceDoc::class, 'source_doc_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
