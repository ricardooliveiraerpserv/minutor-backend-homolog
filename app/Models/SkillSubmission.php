<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Submissão da avaliação. IMUTÁVEL após submit (status=submitted). Enquanto
 * in_progress, `progress` guarda o autosave p/ retomar de onde parou.
 */
class SkillSubmission extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';

    protected $fillable = [
        'survey_id', 'respondent_id', 'matrix_version_id', 'invite_id', 'status',
        'cadastral', 'progress', 'started_at', 'submitted_at', 'ip', 'user_agent',
    ];

    protected $casts = [
        'cadastral' => 'array',
        'progress' => 'array',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(SkillSurvey::class, 'survey_id');
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(SkillRespondent::class, 'respondent_id');
    }

    public function matrixVersion(): BelongsTo
    {
        return $this->belongsTo(SkillMatrixVersion::class, 'matrix_version_id');
    }

    public function invite(): BelongsTo
    {
        return $this->belongsTo(SkillSurveyInvite::class, 'invite_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SkillSubmissionAnswer::class, 'submission_id');
    }
}
