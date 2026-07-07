<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Opção de uma enquete. */
class NotificationPollOption extends Model
{
    protected $table = 'notification_poll_options';

    protected $fillable = ['poll_id', 'label', 'order'];

    protected $casts = ['order' => 'integer'];

    public function poll(): BelongsTo { return $this->belongsTo(NotificationPoll::class, 'poll_id'); }
    public function votes(): HasMany { return $this->hasMany(NotificationPollVote::class, 'option_id'); }
}
