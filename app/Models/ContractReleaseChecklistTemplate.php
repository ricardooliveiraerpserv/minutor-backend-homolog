<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Template de item do Checklist de Liberação (configurável por escopo). */
class ContractReleaseChecklistTemplate extends Model
{
    protected $fillable = [
        'scope_type', 'scope_value', 'item_key', 'label', 'obrigatorio', 'ordem', 'ativo', 'owner_role', 'sla_days',
    ];

    protected $casts = [
        'obrigatorio' => 'boolean',
        'ativo'       => 'boolean',
        'ordem'       => 'integer',
        'sla_days'    => 'integer',
    ];

    public const SCOPE_DEFAULT          = 'default';
    public const SCOPE_CATEGORIA        = 'categoria';
    public const SCOPE_TIPO_FATURAMENTO = 'tipo_faturamento';
    public const SCOPE_CONTRACT_TYPE    = 'contract_type';
}
