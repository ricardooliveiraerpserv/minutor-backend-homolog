<?php

namespace App\Models;

use App\Enums\MessageType;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_id', 'sender_user_id', 'type', 'body', 'metadata', 'reply_to_id',
        'status', 'snoozed_until', 'resolved_by', 'resolved_at',
        'edited_at', 'deleted_at', 'pinned_at', 'pinned_by',
    ];

    protected $casts = [
        'type'          => MessageType::class,
        'metadata'      => 'array',
        'status'        => NotificationStatus::class,
        'created_at'    => 'datetime',
        'snoozed_until' => 'datetime',
        'resolved_at'   => 'datetime',
        'edited_at'     => 'datetime',
        'deleted_at'    => 'datetime',
        'pinned_at'     => 'datetime',
    ];

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', [NotificationStatus::Unread->value, NotificationStatus::Read->value])
            ->orWhere(function ($q) {
                $q->where('status', NotificationStatus::Snoozed->value)
                  ->where('snoozed_until', '<=', now());
            });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(MessageFavorite::class);
    }
}
