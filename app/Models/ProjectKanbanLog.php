<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectKanbanLog extends Model
{
    protected $fillable = ['project_id', 'from_status', 'to_status', 'moved_by_id'];

    public function project(): BelongsTo  { return $this->belongsTo(Project::class); }
    public function movedBy(): BelongsTo  { return $this->belongsTo(User::class, 'moved_by_id'); }
}
