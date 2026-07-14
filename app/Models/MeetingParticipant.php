<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingParticipant extends Model
{
    protected $fillable = [
        'meeting_id', 'user_id', 'customer_contact_id', 'email', 'name', 'role', 'response', 'is_external',
    ];

    protected $casts = ['is_external' => 'boolean'];

    public function meeting(): BelongsTo { return $this->belongsTo(Meeting::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
    public function contact(): BelongsTo { return $this->belongsTo(CustomerContact::class, 'customer_contact_id'); }
}
