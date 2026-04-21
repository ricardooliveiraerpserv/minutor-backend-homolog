<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FechamentoCliente extends Model
{
    protected $table = 'fechamento_clientes';

    protected $fillable = [
        'customer_id',
        'year_month',
        'status',
        'total_servicos',
        'total_despesas',
        'total_geral',
        'snapshot_contratos',
        'snapshot_despesas',
        'snapshot_pagamento',
        'closed_at',
        'closed_by',
        'notes',
    ];

    protected $casts = [
        'total_servicos'     => 'decimal:2',
        'total_despesas'     => 'decimal:2',
        'total_geral'        => 'decimal:2',
        'snapshot_contratos'  => 'array',
        'snapshot_despesas'   => 'array',
        'snapshot_pagamento'  => 'array',
        'closed_at'           => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
