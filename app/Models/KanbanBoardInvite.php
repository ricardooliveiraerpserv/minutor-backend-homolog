<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Convite (pendente/aceito) para um quadro do Kanban do Cliente. Ver a migration
 * create_kanban_board_invites_table. O aceite é feito pelo `token` (link do e-mail /
 * notificação), sem exigir que o convidado já seja membro do quadro.
 */
class KanbanBoardInvite extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACCEPTED = 'accepted';

    protected $fillable = [
        'board_id', 'user_id', 'invited_by', 'token', 'status', 'sent_at', 'accepted_at',
    ];

    protected $casts = [
        'sent_at'     => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(KanbanBoard::class, 'board_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
