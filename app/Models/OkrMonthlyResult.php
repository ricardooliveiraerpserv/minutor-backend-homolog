<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Realização mensal + check-in de um KR. actual_value null = pendente (≠ 0).
 */
class OkrMonthlyResult extends Model
{
    use HasFactory, BelongsToCompany;

    /** Motivo transiente da alteração (lido pelo Observer; não persistido). */
    public ?string $changeReason = null;

    protected $fillable = [
        'company_id', 'okr_key_result_id', 'year_month', 'actual_value',
        'comment', 'risks', 'action_plan', 'situation', 'source', 'reported_by', 'reported_at',
    ];

    protected $casts = [
        'actual_value' => 'decimal:4',
        'reported_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(OkrKeyResult::class, 'okr_key_result_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
