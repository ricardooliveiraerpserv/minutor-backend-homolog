<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Foto semanal do forecast — base para tendência/slippage. */
class CrmForecastSnapshot extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = ['data', 'periodo', 'meta', 'previsto', 'commit', 'best_case', 'pipeline', 'fechado', 'coverage'];
    protected $casts = [
        'data' => 'date', 'meta' => 'decimal:2', 'previsto' => 'decimal:2', 'commit' => 'decimal:2',
        'best_case' => 'decimal:2', 'pipeline' => 'decimal:2', 'fechado' => 'decimal:2', 'coverage' => 'decimal:2',
    ];
}
