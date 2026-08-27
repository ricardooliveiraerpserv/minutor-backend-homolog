<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Pesquisa de Competências (campanha). O tipo define os dados cadastrais e o
 * destino; a MATRIZ é a mesma para todos (matrix_version_id).
 */
class SkillSurvey extends Model
{
    public const TYPE_INTERNAL = 'internal';
    public const TYPE_PARTNER = 'partner';
    public const TYPE_CANDIDATE = 'candidate';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'type', 'title', 'description', 'matrix_version_id', 'public_token',
        'status', 'deadline', 'allow_public', 'created_by', 'opened_at', 'closed_at',
        'is_campaign',
    ];

    protected $casts = [
        'deadline' => 'date',
        'allow_public' => 'boolean',
        'is_campaign' => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SkillSurvey $survey) {
            if (empty($survey->public_token)) {
                $survey->public_token = self::freshToken();
            }
        });
    }

    public static function freshToken(): string
    {
        do {
            $token = Str::upper(Str::random(8));
        } while (self::where('public_token', $token)->exists());

        return $token;
    }

    public function matrixVersion(): BelongsTo
    {
        return $this->belongsTo(SkillMatrixVersion::class, 'matrix_version_id');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(SkillSurveyInvite::class, 'survey_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SkillSubmission::class, 'survey_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
