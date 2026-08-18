<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Central de Fontes — Frente A. Ledger de custo por FONTE (agregado entre passos). Reserva atômica
 * gerida pelo SourceCostGovernor (lockForUpdate). available = authorized − actual − reserved.
 */
class SourceDocCostLedger extends Model
{
    protected $table = 'source_doc_cost_ledger';

    protected $fillable = [
        'source_doc_id', 'actual_cost_usd', 'reserved_cost_usd', 'authorized_limit_usd',
    ];

    protected $casts = [
        'actual_cost_usd' => 'decimal:4',
        'reserved_cost_usd' => 'decimal:4',
        'authorized_limit_usd' => 'decimal:4',
    ];

    public function available(): float
    {
        return (float) $this->authorized_limit_usd - (float) $this->actual_cost_usd - (float) $this->reserved_cost_usd;
    }
}
