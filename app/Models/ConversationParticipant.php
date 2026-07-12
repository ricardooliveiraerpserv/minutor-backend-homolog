<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'user_id', 'role', 'joined_at', 'last_read_at', 'muted', 'muted_until', 'last_typed_at',
    ];

    protected $casts = [
        'joined_at'     => 'datetime',
        'last_read_at'  => 'datetime',
        'muted'         => 'boolean',
        'muted_until'   => 'datetime',
        'last_typed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
