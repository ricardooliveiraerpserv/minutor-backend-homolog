<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Participante da Proposta (P-B) — papéis contextuais à negociação. */
class CrmProposalParticipant extends Model
{
    protected $fillable = [
        'crm_proposal_id', 'name', 'email', 'roles', 'participant_token', 'invited_by',
        'invited_at', 'last_invite_at', 'invite_count', 'accepted_at', 'accepted_ip', 'accepted_user_agent',
        'viewed_at', 'approved_at', 'signed_at', 'last_access_at', 'access_count', 'is_active',
        // P-E.2.0 — evidências de aprovação/assinatura
        'cargo', 'parte', 'approval_comment', 'approval_ip', 'approval_user_agent',
        'sign_name', 'sign_cpf', 'sign_cargo', 'sign_image', 'sign_ip', 'sign_user_agent', 'sign_doc_hash', 'sign_doc_version',
        'sign_status', 'sign_refusal_reason', 'sign_status_at',
    ];

    protected $casts = [
        'roles'          => 'array',
        'invited_at'     => 'datetime',
        'last_invite_at' => 'datetime',
        'invite_count'   => 'integer',
        'accepted_at'    => 'datetime',
        'viewed_at'      => 'datetime',
        'approved_at'    => 'datetime',
        'signed_at'      => 'datetime',
        'last_access_at' => 'datetime',
        'access_count'   => 'integer',
        'is_active'      => 'boolean',
    ];

    public const ROLES = ['viewer', 'reviewer', 'approver', 'signer'];
    public const ROLE_LABELS = ['viewer' => 'Viewer', 'reviewer' => 'Reviewer', 'approver' => 'Approver', 'signer' => 'Signer'];

    public function proposal(): BelongsTo { return $this->belongsTo(CrmProposal::class, 'crm_proposal_id'); }
    public function invitedBy(): BelongsTo { return $this->belongsTo(User::class, 'invited_by'); }

    public static function novoToken(): string
    {
        return Str::lower(Str::random(48));
    }

    public function hasRole(string $r): bool
    {
        return in_array($r, (array) $this->roles, true);
    }

    /** Status derivado: Convidado → Convite Aceito → Visualizou → Aprovou → Assinou (Inativo sobrepõe). */
    public function statusLabel(): string
    {
        if (!$this->is_active) return 'Inativo';
        if ($this->signed_at) return 'Assinou';
        if ($this->approved_at) return 'Aprovou';
        if ($this->viewed_at) return 'Visualizou';
        if ($this->accepted_at) return 'Convite Aceito';
        return 'Convidado';
    }
}
