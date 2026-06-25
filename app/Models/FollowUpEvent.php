<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento da timeline/auditoria do Follow Up (append-only). Espelha StageActivityEvent.
 */
class FollowUpEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null; // append-only

    public const TYPE_CREATED          = 'created';
    public const TYPE_STATUS_CHANGED   = 'status_changed';
    public const TYPE_REASSIGNED       = 'reassigned';
    public const TYPE_DEADLINE_CHANGED = 'deadline_changed';
    public const TYPE_COMMENT          = 'comment';
    public const TYPE_CONCLUDED        = 'concluded';
    public const TYPE_REOPENED         = 'reopened';
    public const TYPE_WAITING_SET      = 'waiting_set';
    public const TYPE_WAITING_CLEARED  = 'waiting_cleared';

    protected $fillable = [
        'follow_up_id', 'actor_user_id', 'type', 'payload',
        'attachment_path', 'attachment_original_name', 'attachment_mime', 'attachment_size',
    ];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public function followUp(): BelongsTo { return $this->belongsTo(FollowUp::class); }
    public function actor(): BelongsTo    { return $this->belongsTo(User::class, 'actor_user_id'); }
}
