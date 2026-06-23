<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** V1.2 — feedback do diagnóstico automático. */
class CrmProposalDiagnosticFeedback extends Model
{
    protected $table = 'crm_proposal_diagnostic_feedback';
    protected $fillable = ['crm_proposal_id', 'user_id', 'resposta', 'comentario'];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
