<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultantHourBankClosing extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'year_month',
        'daily_hours',
        'working_days',
        'holidays_count',
        'expected_hours',
        'worked_hours',
        'month_balance',
        'previous_balance',
        'accumulated_balance',
        'paid_hours',
        'final_balance',
        'status',
        'closed_at',
        'closed_by',
        'notes',
    ];

    protected $casts = [
        'daily_hours'        => 'decimal:2',
        'expected_hours'     => 'decimal:2',
        'worked_hours'       => 'decimal:2',
        'month_balance'      => 'decimal:2',
        'previous_balance'   => 'decimal:2',
        'accumulated_balance'=> 'decimal:2',
        'paid_hours'         => 'decimal:2',
        'final_balance'      => 'decimal:2',
        'working_days'       => 'integer',
        'holidays_count'     => 'integer',
        'closed_at'          => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
