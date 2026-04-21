<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FechamentoParceiro extends Model
{
    protected $table = 'fechamento_parceiros';

    protected $fillable = [
        'partner_id',
        'year_month',
        'status',
        'total_horas',
        'total_despesas',
        'total_a_pagar',
        'snapshot_consultores',
        'snapshot_despesas',
        'closed_at',
        'closed_by',
        'notes',
    ];

    protected $casts = [
        'total_horas'          => 'decimal:2',
        'total_despesas'       => 'decimal:2',
        'total_a_pagar'        => 'decimal:2',
        'snapshot_consultores' => 'array',
        'snapshot_despesas'    => 'array',
        'closed_at'            => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
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
