<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Reunião da Central de Reunião. Visível só aos participantes (envolvidos) + criador; admin vê tudo. */
class Meeting extends Model
{
    use SoftDeletes;

    protected $table = 'meetings';
    protected $fillable = ['title', 'meeting_date', 'location', 'description', 'notes', 'created_by_id'];
    protected $casts = ['meeting_date' => 'datetime'];

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_id'); }
    public function participants(): BelongsToMany { return $this->belongsToMany(User::class, 'meeting_participants')->withTimestamps(); }

    /** Tarefas da reunião (reusa a tabela tasks via entity polimórfica). */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'entity_id')->where('entity_type', 'meeting');
    }

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
