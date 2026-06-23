<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Memória de Cálculo (Fase 1) — entidade própria, congelada por versão.
 */
class CrmProposalCalc extends Model
{
    use SoftDeletes;

    protected $table = 'crm_proposal_calc';

    protected $fillable = [
        'opportunity_id', 'proposal_id', 'versao', 'modo_faturamento',
        'inputs', 'outputs',
        'custo_total', 'faturamento', 'premios_total', 'desconto_valor', 'margem_pct', 'lucro_liquido',
    ];

    protected $casts = [
        'versao'         => 'integer',
        'inputs'         => 'array',
        'outputs'        => 'array',
        'custo_total'    => 'decimal:2',
        'faturamento'    => 'decimal:2',
        'premios_total'  => 'decimal:2',
        'desconto_valor' => 'decimal:2',
        'margem_pct'     => 'decimal:4',
        'lucro_liquido'  => 'decimal:2',
    ];

    public const MODOS = ['por_hora', 'valor_fixo'];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(CrmOpportunity::class, 'opportunity_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CrmProposal::class, 'proposal_id');
    }
}
