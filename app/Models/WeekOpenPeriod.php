<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reabertura semanal. project_id null = global (todos os projetos da semana).
 * Reaberta enquanto closed_at IS NULL E now() <= auto_close_at.
 */
class WeekOpenPeriod extends Model
{
    protected $fillable = ['project_id', 'user_id', 'week_start', 'opened_by', 'closed_by', 'auto_close_at', 'closed_at'];

    protected $casts = [
        'week_start'    => 'date',
        'auto_close_at' => 'datetime',
        'closed_at'     => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
