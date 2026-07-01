<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Seção navegável da proposta (P-C.1) — id estável para comentários/revisões nas próximas fases. */
class CrmProposalSection extends Model
{
    protected $fillable = ['crm_proposal_id', 'section_key', 'title', 'order', 'content', 'data', 'visible'];

    protected $casts = [
        'order'   => 'integer',
        'visible' => 'boolean',
        'data'    => 'array',
    ];

    public function proposal(): BelongsTo { return $this->belongsTo(CrmProposal::class, 'crm_proposal_id'); }
}
