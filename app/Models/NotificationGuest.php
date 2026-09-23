<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acompanhante/familiar que um respondente (user_id) leva a um evento de "Confirmar presença".
 * Criado no respond() quando a notificação tem allow_guests e a resposta é afirmativa.
 */
class NotificationGuest extends Model
{
    protected $table = 'notification_guests';

    protected $fillable = ['notification_id', 'user_id', 'nome', 'parentesco', 'idade'];

    public function notification(): BelongsTo { return $this->belongsTo(AppNotification::class, 'notification_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
