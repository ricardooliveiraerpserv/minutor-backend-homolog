<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationSurveyResponse extends Model
{
    protected $table = 'notification_survey_responses';

    protected $fillable = ['survey_id', 'user_id', 'submitted_at'];

    protected $casts = ['submitted_at' => 'datetime'];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NotificationSurvey::class, 'survey_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(NotificationSurveyAnswer::class, 'response_id');
    }
}
