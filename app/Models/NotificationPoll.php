<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Enquete de uma notificação (type='poll'). */
class NotificationPoll extends Model
{
    protected $table = 'notification_polls';

    protected $fillable = [
        'notification_id', 'question', 'multiple_choice', 'allow_change_vote', 'show_results', 'expires_at',
    ];

    protected $casts = [
        'multiple_choice'   => 'boolean',
        'allow_change_vote' => 'boolean',
        'show_results'      => 'boolean',
        'expires_at'        => 'datetime',
    ];

    public function notification(): BelongsTo { return $this->belongsTo(AppNotification::class, 'notification_id'); }
    public function options(): HasMany { return $this->hasMany(NotificationPollOption::class, 'poll_id')->orderBy('order')->orderBy('id'); }
    public function votes(): HasMany { return $this->hasMany(NotificationPollVote::class, 'poll_id'); }

    /** Votação encerrada? (expires_at no passado). */
    public function isClosed(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
