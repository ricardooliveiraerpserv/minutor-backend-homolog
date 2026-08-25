<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectMessage extends Model
{
    protected $fillable = ['project_id', 'user_id', 'message', 'priority', 'visibility', 'edited_at', 'pinned_at'];

    protected $casts = ['edited_at' => 'datetime', 'pinned_at' => 'datetime'];

    /** Janela de edição da própria última interação (horas). */
    public const EDIT_WINDOW_HOURS = 3;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(ProjectMessageMention::class, 'message_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ProjectMessageRead::class, 'message_id');
    }

    /**
     * Anexos da mensagem — FASE 11.7 (PR 7b): polimórficos via tabela `attachments`.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'entity_id')
            ->where('attachments.entity_type', 'PROJECT_MESSAGE')
            ->whereNull('attachments.deleted_at');
    }
}
