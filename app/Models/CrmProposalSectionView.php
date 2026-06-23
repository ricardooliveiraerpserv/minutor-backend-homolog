<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tracking de permanência por seção (P-C.1) — section_entered/exited + duration. */
class CrmProposalSectionView extends Model
{
    protected $fillable = [
        'crm_proposal_id', 'crm_proposal_share_id', 'crm_proposal_participant_id',
        'section_key', 'entered_at', 'exited_at', 'duration_seconds',
    ];

    protected $casts = [
        'entered_at'       => 'datetime',
        'exited_at'        => 'datetime',
        'duration_seconds' => 'integer',
    ];
}
