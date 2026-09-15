<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanLabel extends Model
{
    protected $fillable = ['board_id', 'name', 'color'];

    public function board(): BelongsTo { return $this->belongsTo(KanbanBoard::class, 'board_id'); }
}
