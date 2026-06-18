<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CRM — Proposta comercial de uma oportunidade. */
class CrmProposal extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'opportunity_id', 'customer_id', 'codigo', 'tipo', 'numero', 'versao', 'data_emissao', 'data_validade',
        'valor', 'descontos', 'vendedor_id', 'memoria_calculo', 'conteudo', 'calc_id', 'document_id', 'status', 'created_by_id',
    ];

    protected $casts = [
        'numero'        => 'integer',
        'versao'        => 'integer',
        'data_emissao'  => 'date',
        'data_validade' => 'date',
        'valor'         => 'decimal:2',
        'descontos'     => 'decimal:2',
        'memoria_calculo' => 'array',
        'conteudo'        => 'array',
    ];

    // Status oficiais (Fase 0.6): 9 estados.
    public const STATUSES = [
        'em_elaboracao', 'enviada', 'em_negociacao', 'aprovada',
        'reprovada', 'cancelada', 'expirada', 'reativada', 'convertida',
    ];

    public function opportunity(): BelongsTo { return $this->belongsTo(CrmOpportunity::class, 'opportunity_id'); }
    public function customer(): BelongsTo    { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function vendedor(): BelongsTo    { return $this->belongsTo(User::class, 'vendedor_id'); }
    public function calc(): BelongsTo        { return $this->belongsTo(CrmProposalCalc::class, 'calc_id'); }
    public function document(): BelongsTo    { return $this->belongsTo(Document::class, 'document_id'); }

    public function getTotalAttribute(): float
    {
        return round((float) $this->valor - (float) $this->descontos, 2);
    }
}
