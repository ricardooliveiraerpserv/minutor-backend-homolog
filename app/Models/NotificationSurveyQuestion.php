<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationSurveyQuestion extends Model
{
    protected $table = 'notification_survey_questions';

    protected $fillable = ['survey_id', 'label', 'type', 'required', 'multiple', 'order'];

    protected $casts = [
        'required' => 'boolean',
        'multiple' => 'boolean',
        'order'    => 'integer',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NotificationSurvey::class, 'survey_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(NotificationSurveyQuestionOption::class, 'question_id')->orderBy('order')->orderBy('id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(NotificationSurveyAnswer::class, 'question_id');
    }
}
