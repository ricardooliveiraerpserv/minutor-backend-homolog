<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Timeline imutável da reunião (mesmo desenho de helpdesk_ticket_events).
 * Só created_at; nunca update/delete.
 */
class MeetingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['meeting_id', 'event_type', 'meta', 'triggered_by', 'created_at'];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public function meeting(): BelongsTo     { return $this->belongsTo(Meeting::class); }
    public function triggeredBy(): BelongsTo  { return $this->belongsTo(User::class, 'triggered_by'); }

    /** Registra um evento na timeline da reunião. */
    public static function log(int $meetingId, string $type, array $extra = []): self
    {
        return self::create(array_merge([
            'meeting_id'   => $meetingId,
            'event_type'   => $type,
            'triggered_by' => auth()->id(),
            'created_at'   => now(),
        ], $extra));
    }
}
