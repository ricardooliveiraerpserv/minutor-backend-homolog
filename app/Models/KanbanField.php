<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Campo configurável de um quadro (Fase 2). */
class KanbanField extends Model
{
    use SoftDeletes;

    protected $fillable = ['board_id', 'name', 'type', 'required', 'show_on_front', 'options', 'default_value', 'position'];
    protected $casts = [
        'required'      => 'boolean',
        'show_on_front' => 'boolean',
        'options'       => 'array',
        'position'      => 'integer',
    ];

    public const TYPES = ['text', 'textarea', 'number', 'money', 'date', 'datetime', 'select', 'multiselect', 'checkbox', 'link_user'];

    public function board(): BelongsTo { return $this->belongsTo(KanbanBoard::class, 'board_id'); }
}
