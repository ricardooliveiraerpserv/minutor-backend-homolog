<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encerramento manual de competência (semana|mês) antes do prazo. project_id/user_id null = global.
 */
class CompetenceClosure extends Model
{
    protected $fillable = ['period_kind', 'period_key', 'project_id', 'user_id', 'closed_by', 'closed_at'];

    protected $casts = ['closed_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
