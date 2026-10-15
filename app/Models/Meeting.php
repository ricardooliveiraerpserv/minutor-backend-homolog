<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reunião.
 *
 * Dois usos convivem na MESMA tabela/model:
 *  - ANTIGO (Central de Reunião): meeting_date + tarefas; participantes via belongsToMany `participants()`
 *    (pivô user_id). Reuniões antigas têm origin_type NULL.
 *  - NOVO (Central de Reuniões / Teams a partir do chamado): starts_at + provider + origem polimórfica;
 *    participantes via hasMany `participantRows()` (MeetingParticipant, com e-mail/papel/externo) +
 *    timeline `events()`. Reuniões novas têm origin_type preenchido.
 */
class Meeting extends Model
{
    use SoftDeletes;

    public const PROVIDERS = ['teams', 'meet', 'zoom', 'webex', 'presencial'];
    public const STATUSES  = ['scheduled', 'live', 'ended', 'canceled'];

    protected $table = 'meetings';

    protected $fillable = [
        // antigo
        'title', 'meeting_date', 'location', 'description', 'notes', 'created_by_id',
        // novo
        'provider', 'status', 'starts_at', 'ends_at', 'duration_minutes', 'timezone',
        'organizer_user_id', 'origin_type', 'origin_id', 'external_meeting_id', 'join_url',
        'provider_data', 'started_at', 'ended_at', 'summary', 'ata', 'transcript',
        'recording_url', 'transcript_url',
    ];

    protected $casts = [
        'meeting_date'     => 'datetime',
        'starts_at'        => 'datetime',
        'ends_at'          => 'datetime',
        'started_at'       => 'datetime',
        'ended_at'         => 'datetime',
        'duration_minutes' => 'integer',
        'provider_data'    => 'array',
    ];

    // ---- Relations comuns ----
    public function creator(): BelongsTo   { return $this->belongsTo(User::class, 'created_by_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_id'); }
    public function organizer(): BelongsTo { return $this->belongsTo(User::class, 'organizer_user_id'); }

    /** ANTIGO — participantes internos como pivô (Central de Reunião). */
    public function participants(): BelongsToMany { return $this->belongsToMany(User::class, 'meeting_participants')->withTimestamps(); }

    /** NOVO — participantes como entidade (e-mail/papel/externo), modelo novo. */
    public function participantRows(): HasMany { return $this->hasMany(MeetingParticipant::class); }

    /** NOVO — timeline imutável. */
    public function events(): HasMany { return $this->hasMany(MeetingEvent::class); }

    /** Tarefas da reunião (reusa a tabela tasks via entity polimórfica) — Central antiga. */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'entity_id')->where('entity_type', 'meeting');
    }

    /** NOVO — resolve o model da ORIGEM via registry (chamado/projeto/cliente/contrato). */
    public function origin(): ?Model
    {
        $class = \App\Meetings\MeetingOriginRegistry::modelFor($this->origin_type);
        return ($class && $this->origin_id) ? $class::find($this->origin_id) : null;
    }

    public function isPast(): bool     { return $this->ends_at ? $this->ends_at->isPast() : (bool) $this->starts_at?->isPast(); }
    public function isUpcoming(): bool { return $this->status === 'scheduled' && (bool) $this->starts_at?->isFuture(); }

    // ---- Visibilidade da Central antiga ----
    /** Só envolvidos (participantes) + criador veem; admin vê tudo. */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isAdmin()) return $query;
        return $query->where(function ($q) use ($user) {
            $q->where('created_by_id', $user->id)
              ->orWhereHas('participants', fn ($p) => $p->where('users.id', $user->id));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->isAdmin()
            || $this->created_by_id === $user->id
            || $this->participants()->where('users.id', $user->id)->exists();
    }
}
