<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectCoordinatorPercentageLog extends Model
{
    protected $fillable = [
        'project_id',
        'changed_by',
        'previous_percentage',
        'new_percentage',
        'previous_balance',
        'new_balance',
    ];

    protected $casts = [
        'previous_percentage' => 'decimal:2',
        'new_percentage'      => 'decimal:2',
        'previous_balance'    => 'decimal:2',
        'new_balance'         => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
