<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Thread de revisão vinculada a uma SEÇÃO da proposta (P-C.2). */
class CrmProposalReviewThread extends Model
{
    protected $fillable = [
        'crm_proposal_id', 'crm_proposal_section_id', 'section_key', 'created_by_participant_id',
        'subject', 'status', 'opened_revision_number', 'resolved_revision_number',
        'resolved_at', 'resolved_by_participant_id', 'resolved_by_user_id',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'opened_revision_number' => 'integer',
        'resolved_revision_number' => 'integer',
    ];

    /** Pendente = aberta ou respondida aguardando validação (não resolvida). */
    public const STATUSES_PENDENTES = ['aberta', 'respondida'];

    public const STATUSES = ['aberta', 'respondida', 'resolvida'];

    public function proposal(): BelongsTo { return $this->belongsTo(CrmProposal::class, 'crm_proposal_id'); }
    public function section(): BelongsTo { return $this->belongsTo(CrmProposalSection::class, 'crm_proposal_section_id'); }
    public function author(): BelongsTo { return $this->belongsTo(CrmProposalParticipant::class, 'created_by_participant_id'); }
    public function messages(): HasMany { return $this->hasMany(CrmProposalReviewMessage::class, 'crm_proposal_review_thread_id')->orderBy('id'); }

    public function isResolvida(): bool { return $this->status === 'resolvida'; }
}
