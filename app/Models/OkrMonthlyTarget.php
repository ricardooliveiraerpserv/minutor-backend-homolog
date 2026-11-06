<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta mensal de um KR (competência year_month). target_value null = KR inativo no mês.
 */
class OkrMonthlyTarget extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'okr_key_result_id', 'year_month', 'target_value',
    ];

    protected $casts = [
        'target_value' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(OkrKeyResult::class, 'okr_key_result_id');
    }
}
