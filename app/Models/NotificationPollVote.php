<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Voto de um usuário numa opção (auditoria: user_id + opção + data/hora). */
class NotificationPollVote extends Model
{
    protected $table = 'notification_poll_votes';

    protected $fillable = ['poll_id', 'option_id', 'user_id'];

    public function poll(): BelongsTo { return $this->belongsTo(NotificationPoll::class, 'poll_id'); }
    public function option(): BelongsTo { return $this->belongsTo(NotificationPollOption::class, 'option_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
