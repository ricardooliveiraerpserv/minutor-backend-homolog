<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Quadro Kanban do cliente (Fase 1). Pertence a um customer. */
class KanbanBoard extends Model
{
    use SoftDeletes;

    protected $fillable = ['customer_id', 'created_by_user_id', 'name', 'description', 'color', 'position'];
    protected $casts = ['position' => 'integer'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function columns(): HasMany { return $this->hasMany(KanbanColumn::class, 'board_id')->orderBy('position')->orderBy('id'); }
    public function labels(): HasMany { return $this->hasMany(KanbanLabel::class, 'board_id')->orderBy('id'); }
    public function cards(): HasMany { return $this->hasMany(KanbanCard::class, 'board_id'); }
}
