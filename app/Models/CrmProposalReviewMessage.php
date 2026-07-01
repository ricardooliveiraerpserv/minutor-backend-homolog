<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensagem de uma thread de revisão (P-C.2). Append-only — histórico preservado. */
class CrmProposalReviewMessage extends Model
{
    protected $fillable = [
        'crm_proposal_review_thread_id', 'crm_proposal_participant_id', 'author_user_id', 'message',
    ];

    public function thread(): BelongsTo { return $this->belongsTo(CrmProposalReviewThread::class, 'crm_proposal_review_thread_id'); }
    public function participant(): BelongsTo { return $this->belongsTo(CrmProposalParticipant::class, 'crm_proposal_participant_id'); }
    public function authorUser(): BelongsTo { return $this->belongsTo(User::class, 'author_user_id'); }
}
