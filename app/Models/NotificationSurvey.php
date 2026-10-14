<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationSurvey extends Model
{
    protected $table = 'notification_surveys';

    protected $fillable = ['notification_id', 'anonymous', 'expires_at'];

    protected $casts = [
        'anonymous'  => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(AppNotification::class, 'notification_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(NotificationSurveyQuestion::class, 'survey_id')->orderBy('order')->orderBy('id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(NotificationSurveyResponse::class, 'survey_id');
    }

    public function isClosed(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
