<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de visualização/aceite de uma notificação por usuário (auditoria). */
class NotificationRead extends Model
{
    protected $table = 'notification_reads';

    protected $fillable = [
        'notification_id', 'user_id', 'viewed_at', 'ack_at', 'acked_version', 'response_action', 'ack_ip', 'ack_user_agent',
    ];

    protected $casts = [
        'viewed_at'     => 'datetime',
        'ack_at'        => 'datetime',
        'acked_version' => 'integer',
    ];

    public function notification(): BelongsTo { return $this->belongsTo(AppNotification::class, 'notification_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
