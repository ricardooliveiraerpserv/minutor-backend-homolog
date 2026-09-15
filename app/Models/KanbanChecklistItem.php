<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanChecklistItem extends Model
{
    protected $fillable = ['card_id', 'text', 'is_done', 'position'];
    protected $casts = ['is_done' => 'boolean', 'position' => 'integer'];

    public function card(): BelongsTo { return $this->belongsTo(KanbanCard::class, 'card_id'); }
}
