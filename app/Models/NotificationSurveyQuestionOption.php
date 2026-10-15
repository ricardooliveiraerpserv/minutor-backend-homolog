<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSurveyQuestionOption extends Model
{
    protected $table = 'notification_survey_question_options';

    protected $fillable = ['question_id', 'label', 'order'];

    protected $casts = ['order' => 'integer'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(NotificationSurveyQuestion::class, 'question_id');
    }
}
