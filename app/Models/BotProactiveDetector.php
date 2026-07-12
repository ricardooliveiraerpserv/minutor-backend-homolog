<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BotProactiveDetector extends Model
{
    protected $fillable = [
        'slug', 'name', 'description', 'active',
        'detector_type', 'config', 'severity',
        'source', 'event_type', 'dedupe_window_hours',
        'is_system',
        'last_run_at', 'last_run_alerts', 'last_run_error',
    ];

    protected $casts = [
        'active'              => 'boolean',
        'is_system'           => 'boolean',
        'config'              => 'array',
        'dedupe_window_hours' => 'integer',
        'last_run_at'         => 'datetime',
        'last_run_alerts'     => 'integer',
    ];

    public const TYPES = [
        'bank_hours_threshold',
        'expense_payment_age',
        'timesheet_pending_age',
        'ticket_stale_age',
        'late_timesheets',
        'sql',
        'custom',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }
}
