<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSurveyAnswer extends Model
{
    protected $table = 'notification_survey_answers';

    protected $fillable = ['response_id', 'question_id', 'answer_text', 'answer_user_id', 'option_id'];

    public function response(): BelongsTo
    {
        return $this->belongsTo(NotificationSurveyResponse::class, 'response_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(NotificationSurveyQuestion::class, 'question_id');
    }

    public function answerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answer_user_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(NotificationSurveyQuestionOption::class, 'option_id');
    }
}
