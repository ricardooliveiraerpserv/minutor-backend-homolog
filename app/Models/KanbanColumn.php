<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KanbanColumn extends Model
{
    protected $fillable = ['board_id', 'name', 'color', 'position'];
    protected $casts = ['position' => 'integer'];

    public function board(): BelongsTo { return $this->belongsTo(KanbanBoard::class, 'board_id'); }
    public function cards(): HasMany { return $this->hasMany(KanbanCard::class, 'column_id')->orderBy('position')->orderBy('id'); }
}
