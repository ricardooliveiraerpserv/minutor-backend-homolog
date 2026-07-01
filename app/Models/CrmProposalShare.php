<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Acesso público (tokenizado) a uma proposta — camada do Portal de Propostas.
 * Não duplica versionamento/PDF/status/auditoria: aponta p/ CrmProposal + Document,
 * e os eventos de engajamento ficam em DocumentEvent. Aqui só vive o controle de
 * ACESSO (token/validade/revogação) + cache de indicadores.
 */
class CrmProposalShare extends Model
{
    protected $table = 'crm_proposal_shares';

    protected $fillable = [
        'proposal_id', 'document_id', 'token', 'destinatario', 'enviado_por',
        'sent_at', 'expires_at', 'revoked_at',
        'first_viewed_at', 'last_viewed_at', 'view_count', 'read_seconds',
        'accepted_at', 'rejected_at', 'reject_reason', 'expired_marked_at',
    ];

    protected $casts = [
        'sent_at'           => 'datetime',
        'expires_at'        => 'datetime',
        'revoked_at'        => 'datetime',
        'first_viewed_at'   => 'datetime',
        'last_viewed_at'    => 'datetime',
        'accepted_at'       => 'datetime',
        'rejected_at'       => 'datetime',
        'expired_marked_at' => 'datetime',
        'view_count'        => 'integer',
        'read_seconds'      => 'integer',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CrmProposal::class, 'proposal_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    /** Token opaco, não sequencial (URL-safe). */
    public static function novoToken(): string
    {
        return Str::lower(Str::random(48));
    }

    public function isRevogado(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpirado(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Link ainda válido para abrir o portal. */
    public function isAtivo(): bool
    {
        return !$this->isRevogado() && !$this->isExpirado();
    }
}
