<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resposta por competência dentro de uma submissão (nunca sobrescrita entre
 * avaliações). level_weight desnormalizado p/ o histórico sobreviver a
 * mudanças em skill_levels. "Nenhum conhecimento" = level weight 0.
 */
class SkillSubmissionAnswer extends Model
{
    protected $fillable = [
        'submission_id', 'matrix_version_item_id', 'skill_id', 'level_id',
        'level_weight', 'years_experience', 'atuacao', 'notes',
    ];

    protected $casts = [
        'atuacao' => 'array',
        'level_weight' => 'integer',
        'years_experience' => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(SkillSubmission::class, 'submission_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SkillMatrixVersionItem::class, 'matrix_version_item_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(SkillLevel::class, 'level_id');
    }
}
