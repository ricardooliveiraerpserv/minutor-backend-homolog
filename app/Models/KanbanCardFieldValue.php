<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanCardFieldValue extends Model
{
    protected $fillable = ['card_id', 'field_id', 'value'];

    public function card(): BelongsTo { return $this->belongsTo(KanbanCard::class, 'card_id'); }
    public function field(): BelongsTo { return $this->belongsTo(KanbanField::class, 'field_id'); }
}
