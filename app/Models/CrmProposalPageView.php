<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Leitura real do PDF por página (P-E.1.1). */
class CrmProposalPageView extends Model
{
    protected $fillable = [
        'crm_proposal_id', 'crm_proposal_share_id', 'crm_proposal_participant_id',
        'page', 'total_pages', 'entered_at', 'exited_at', 'duration_seconds',
    ];

    protected $casts = [
        'page'             => 'integer',
        'total_pages'      => 'integer',
        'entered_at'       => 'datetime',
        'exited_at'        => 'datetime',
        'duration_seconds' => 'integer',
    ];
}
