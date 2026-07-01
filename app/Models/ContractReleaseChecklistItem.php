<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item do Checklist de Liberação instanciado por contrato (snapshot do template). */
class ContractReleaseChecklistItem extends Model
{
    protected $fillable = [
        'contract_id', 'crm_proposal_id', 'item_key', 'label', 'obrigatorio', 'aplicavel', 'checked', 'checked_by', 'checked_at', 'ordem', 'owner_role', 'sla_days',
    ];

    protected $casts = [
        'obrigatorio' => 'boolean',
        'aplicavel'   => 'boolean',
        'checked'     => 'boolean',
        'checked_at'  => 'datetime',
        'ordem'       => 'integer',
        'sla_days'    => 'integer',
    ];

    /** Rótulo PT-BR da área responsável. */
    public const OWNER_LABELS = [
        'comercial' => 'Comercial', 'administrativo' => 'Administrativo',
        'operacoes' => 'Operações', 'juridico' => 'Jurídico',
    ];

    public function contract(): BelongsTo    { return $this->belongsTo(Contract::class); }
    public function crmProposal(): BelongsTo { return $this->belongsTo(CrmProposal::class, 'crm_proposal_id'); }
    public function checkedBy(): BelongsTo   { return $this->belongsTo(User::class, 'checked_by'); }
}
