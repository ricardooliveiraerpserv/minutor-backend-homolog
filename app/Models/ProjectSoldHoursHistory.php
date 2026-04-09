<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSoldHoursHistory extends Model
{
    protected $fillable = [
        'project_id',
        'sold_hours',
        'effective_from',
        'changed_by',
    ];

    protected $casts = [
        'sold_hours'     => 'integer',
        'effective_from' => 'date',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by')->select(['id', 'name', 'email']);
    }
}
