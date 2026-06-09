<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento append-only da timeline operacional de uma etapa.
 * Ver ADR 0005.
 */
class StageActivityEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_DELIVERY_MOVED     = 'delivery_moved';
    public const TYPE_DELIVERY_CREATED   = 'delivery_created';
    public const TYPE_DELIVERY_COMPLETED = 'delivery_completed';
    public const TYPE_HOURS_LOGGED       = 'hours_logged';
    public const TYPE_APORTE_CREATED     = 'aporte_created';
    public const TYPE_BLOCK_SET          = 'block_set';
    public const TYPE_BLOCK_CLEARED      = 'block_cleared';
    public const TYPE_COMMENT            = 'comment';
    public const TYPE_CLIENT_INVOLVED    = 'client_involved';
    public const TYPE_CLIENT_REMOVED     = 'client_removed';
    public const TYPE_APPROVAL_REQUESTED = 'approval_requested';
    public const TYPE_APPROVAL_APPROVED  = 'approval_approved';
    public const TYPE_APPROVAL_REJECTED  = 'approval_rejected';

    // Público-alvo da mensagem (além de admin/coordenador, que veem tudo).
    public const AUDIENCE_CLIENTE   = 'cliente';
    public const AUDIENCE_CONSULTOR = 'consultor';
    public const AUDIENCES = [self::AUDIENCE_CLIENTE, self::AUDIENCE_CONSULTOR];

    protected $fillable = [
        'stage_id',
        'delivery_id',
        'actor_user_id',
        'type',
        'payload',
        'audiences',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime',
        'attachment_size',
    ];

    protected $casts = [
        'payload'    => 'array',
        'audiences'  => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Esta mensagem é visível para o usuário? Admin/coordenador veem tudo.
     * Eventos não-comentário (status, aprovação, etc.) são sempre visíveis.
     * Comentário: cliente vê se 'cliente' no público; consultor se 'consultor'
     * no público OU se for o autor.
     */
    public function visibleTo(?User $user): bool
    {
        if (!$user) return false;
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) return true;
        if (method_exists($user, 'isCoordenador') && $user->isCoordenador()) return true;
        if ($this->type !== self::TYPE_COMMENT) return true;

        $aud = $this->audiences ?? [];
        if (method_exists($user, 'isCliente') && $user->isCliente()) {
            return in_array(self::AUDIENCE_CLIENTE, $aud, true);
        }
        // Consultor (e demais perfis internos não-admin/coord): vê se marcado consultor ou se for o autor.
        return in_array(self::AUDIENCE_CONSULTOR, $aud, true) || (int) $this->actor_user_id === (int) $user->id;
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'stage_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(StageDelivery::class, 'delivery_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
