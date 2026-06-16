<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste inicial de rentabilidade por cliente × ano (custo/receita lançados manualmente).
 */
class CustomerRentabInitial extends Model
{
    protected $fillable = ['customer_id', 'year', 'custo_inicial', 'receita_inicial'];

    protected $casts = [
        'year'            => 'integer',
        'custo_inicial'   => 'decimal:2',
        'receita_inicial' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
