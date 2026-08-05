<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClosingLog extends Model
{
    protected $fillable = ['event', 'period_kind', 'period_key', 'project_id', 'user_id', 'occurred_at', 'note'];

    protected $casts = ['occurred_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
