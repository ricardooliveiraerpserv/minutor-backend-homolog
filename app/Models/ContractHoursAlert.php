<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico de alertas de consumo de horas por contrato/projeto.
 * Cada linha = uma faixa (70/80/90/100/110%...) atingida num "período" (snapshot do
 * limite). Serve tanto de log de auditoria quanto de controle de dedup (não reenviar
 * a mesma faixa) e de origem para o reenvio manual em caso de falha.
 */
class ContractHoursAlert extends Model
{
    protected $fillable = [
        'project_id', 'contract_id', 'band', 'available_snapshot',
        'percentual', 'available', 'consumed', 'approved', 'balance',
        'basis', 'classification', 'recipients_to', 'recipients_cc',
        'status', 'error', 'sent_at',
    ];

    protected $casts = [
        'band'               => 'integer',
        'available_snapshot' => 'integer',
        'percentual'         => 'decimal:2',
        'available'          => 'decimal:2',
        'consumed'           => 'decimal:2',
        'approved'           => 'decimal:2',
        'balance'            => 'decimal:2',
        'recipients_to'      => 'array',
        'recipients_cc'      => 'array',
        'sent_at'            => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
