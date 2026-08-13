<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comentário da CONVERSA GLOBAL do projeto (cliente ↔ equipe).
 * Canal por projeto, separado do Diário interno (project_messages) e dos
 * comentários por atividade (stage_activity_events). Sem horas/valores.
 */
class ProjectClientComment extends Model
{
    protected $fillable = [
        'project_id', 'user_id', 'body',
        'attachment_path', 'attachment_original_name', 'attachment_mime', 'attachment_size',
        'from_client',
    ];

    protected $casts = ['from_client' => 'boolean'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
