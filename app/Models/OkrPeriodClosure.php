<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fechamento mensal de OKR por área (ou global). Status aberto|em_revisao|fechado.
 */
class OkrPeriodClosure extends Model
{
    use HasFactory, BelongsToCompany;

    public const STATUSES = ['aberto', 'em_revisao', 'fechado'];

    protected $fillable = [
        'company_id', 'okr_cycle_id', 'okr_area_id', 'year_month', 'status',
        'opened_by', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isClosed(): bool
    {
        return $this->status === 'fechado';
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(OkrCycle::class, 'okr_cycle_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(OkrArea::class, 'okr_area_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
