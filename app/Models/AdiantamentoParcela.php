<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdiantamentoParcela extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $table = 'adiantamento_parcelas';

    protected $fillable = [
        'adiantamento_id',
        'numero',
        'year_month',
        'valor',
    ];

    protected $casts = [
        'numero' => 'integer',
        'valor'  => 'decimal:2',
    ];

    public function adiantamento(): BelongsTo
    {
        return $this->belongsTo(Adiantamento::class);
    }
}
