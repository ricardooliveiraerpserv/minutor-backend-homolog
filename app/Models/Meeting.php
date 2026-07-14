<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Central de Reuniões — uma reunião é entidade própria, polimórfica na ORIGEM
 * (chamado/projeto/cliente/contrato/agenda). O provider (Teams etc.) é um adapter à parte.
 */
class Meeting extends Model
{
    use SoftDeletes;

    public const PROVIDERS = ['teams', 'meet', 'zoom', 'webex', 'presencial'];
    public const STATUSES  = ['scheduled', 'live', 'ended', 'canceled'];

    protected $fillable = [
        'title', 'description', 'provider', 'status', 'starts_at', 'ends_at', 'duration_minutes',
        'timezone', 'organizer_user_id', 'origin_type', 'origin_id', 'external_meeting_id',
        'join_url', 'provider_data', 'started_at', 'ended_at', 'summary', 'ata',
        'recording_url', 'transcript_url', 'transcript', 'created_by_id',
    ];

    protected $casts = [
        'starts_at'        => 'datetime',
        'ends_at'          => 'datetime',
        'started_at'       => 'datetime',
        'ended_at'         => 'datetime',
        'duration_minutes' => 'integer',
        'provider_data'    => 'array',
    ];

    public function organizer(): BelongsTo   { return $this->belongsTo(User::class, 'organizer_user_id'); }
    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by_id'); }
    public function participants(): HasMany   { return $this->hasMany(MeetingParticipant::class); }
    public function events(): HasMany         { return $this->hasMany(MeetingEvent::class); }

    /** Resolve o model da ORIGEM via registry (chamado/projeto/cliente/contrato). */
    public function origin(): ?Model
    {
        $class = \App\Meetings\MeetingOriginRegistry::modelFor($this->origin_type);
        return ($class && $this->origin_id) ? $class::find($this->origin_id) : null;
    }

    public function isPast(): bool     { return $this->ends_at ? $this->ends_at->isPast() : $this->starts_at->isPast(); }
    public function isUpcoming(): bool { return $this->status === 'scheduled' && $this->starts_at->isFuture(); }
}
