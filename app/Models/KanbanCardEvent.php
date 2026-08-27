<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evento de auditoria do Kanban do cliente (Fase 4). Só created_at. */
class KanbanCardEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['board_id', 'card_id', 'user_id', 'type', 'from_column_id', 'to_column_id', 'card_title', 'meta'];
    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function card(): BelongsTo { return $this->belongsTo(KanbanCard::class, 'card_id'); }
}
