<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FechamentoAdministrativo extends Model
{
    protected $table = 'fechamento_administrativos';

    protected $fillable = [
        'year_month',
        'status',
        'total_custo_interno',
        'total_custo_parceiros',
        'total_receita',
        'margem',
        'margem_percentual',
        'snapshot_producao',
        'snapshot_custo',
        'snapshot_receita',
        'closed_at',
        'closed_by',
        'notes',
    ];

    protected $casts = [
        'total_custo_interno'   => 'decimal:2',
        'total_custo_parceiros' => 'decimal:2',
        'total_receita'         => 'decimal:2',
        'margem'                => 'decimal:2',
        'margem_percentual'     => 'decimal:4',
        'snapshot_producao'     => 'array',
        'snapshot_custo'        => 'array',
        'snapshot_receita'      => 'array',
        'closed_at'             => 'datetime',
    ];

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
