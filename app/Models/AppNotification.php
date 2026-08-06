<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Notificação da Central (tela inicial). Nome AppNotification p/ não colidir com o Notification do Laravel. */
class AppNotification extends Model
{
    use SoftDeletes, \App\Models\Concerns\BelongsToCompany;

    protected $table = 'notifications_center';

    protected $fillable = [
        'title', 'message', 'type', 'priority', 'target_roles', 'target_users',
        'target_contract_types', 'target_bonds', 'excluded_user_ids', 'target_customer_id', 'target_customer_ids', 'send_email', 'visible',
        'requires_ack', 'cta_label', 'cta_url', 'actions', 'version', 'created_by', 'expires_at',
        'recurrence', 'recurrence_value', 'recurrence_weekdays', 'last_fired_at', 'resent_at', 'is_template', 'template_name',
    ];

    protected $casts = [
        'target_roles'          => 'array',
        'target_users'          => 'array',
        'target_contract_types' => 'array',
        'target_bonds'          => 'array',
        'excluded_user_ids'     => 'array',
        'target_customer_ids'   => 'array',
        'actions'               => 'array',
        'requires_ack'          => 'boolean',
        'send_email'            => 'boolean',
        'visible'               => 'boolean',
        'is_template'           => 'boolean',
        'recurrence_value'      => 'integer',
        'recurrence_weekdays'   => 'array',
        'version'               => 'integer',
        'expires_at'            => 'datetime',
        'last_fired_at'         => 'datetime',
        'resent_at'             => 'datetime',
    ];

    public const TYPES = ['info', 'action', 'require_ack', 'poll', 'aviso', 'formal'];
    public const RECURRENCES = ['none', 'every_hours', 'every_days', 'weekly', 'day_of_month', 'business_day'];
    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];
    public const PRIORITY_RANK = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];

    public function reads(): HasMany { return $this->hasMany(NotificationRead::class, 'notification_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function poll(): HasOne { return $this->hasOne(NotificationPoll::class, 'notification_id'); }
}
