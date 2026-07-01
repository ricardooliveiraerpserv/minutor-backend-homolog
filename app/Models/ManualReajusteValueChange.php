<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico de reajuste de uma inclusão manual (espelha ContractValueChange). */
class ManualReajusteValueChange extends Model
{
    protected $table = 'manual_reajuste_value_changes';

    protected $fillable = [
        'manual_reajuste_id', 'valor_anterior', 'valor_novo', 'percentual',
        'indice', 'periodo_inicio', 'periodo_fim', 'periodo_formatado', 'user_id', 'reversed_at',
    ];

    protected $casts = [
        'valor_anterior' => 'decimal:2',
        'valor_novo'     => 'decimal:2',
        'percentual'     => 'decimal:4',
        'periodo_inicio' => 'date:Y-m-d',
        'periodo_fim'    => 'date:Y-m-d',
        'reversed_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
