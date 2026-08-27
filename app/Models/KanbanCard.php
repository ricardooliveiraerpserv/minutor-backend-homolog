<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KanbanCard extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'board_id', 'column_id', 'title', 'description', 'responsible_user_id',
        'start_date', 'due_date', 'priority', 'position', 'created_by_user_id',
    ];
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'due_date'   => 'date:Y-m-d',
        'position'   => 'integer',
    ];

    public function board(): BelongsTo { return $this->belongsTo(KanbanBoard::class, 'board_id'); }
    public function column(): BelongsTo { return $this->belongsTo(KanbanColumn::class, 'column_id'); }
    public function responsible(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function labels(): BelongsToMany { return $this->belongsToMany(KanbanLabel::class, 'kanban_card_label', 'card_id', 'label_id'); }
    public function checklistItems(): HasMany { return $this->hasMany(KanbanChecklistItem::class, 'card_id')->orderBy('position')->orderBy('id'); }
    public function comments(): HasMany { return $this->hasMany(KanbanCardComment::class, 'card_id')->orderByDesc('created_at'); }

    /** Anexos do card (infra FASE 11). */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'entity_id')->where('entity_type', self::attachmentEntityType());
    }
    public static function attachmentEntityType(): string { return 'KANBAN_CARD'; }
}
