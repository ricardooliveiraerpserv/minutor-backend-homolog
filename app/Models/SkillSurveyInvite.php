<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Convite individual + tracking (pending → sent → opened → started → submitted).
 */
class SkillSurveyInvite extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_OPENED = 'opened';
    public const STATUS_STARTED = 'started';
    public const STATUS_SUBMITTED = 'submitted';

    protected $fillable = [
        'survey_id', 'respondent_id', 'user_id', 'email', 'name', 'token', 'status',
        'submission_id', 'sent_at', 'opened_at', 'started_at', 'submitted_at',
        'last_access_at', 'reminder_count', 'last_reminder_at', 'disabled_at',
    ];

    protected $casts = [
        'disabled_at' => 'datetime',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'last_access_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'reminder_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (SkillSurveyInvite $invite) {
            if (empty($invite->token)) {
                $invite->token = self::freshToken();
            }
        });
    }

    public static function freshToken(): string
    {
        do {
            $token = Str::random(24);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(SkillSurvey::class, 'survey_id');
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(SkillRespondent::class, 'respondent_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
