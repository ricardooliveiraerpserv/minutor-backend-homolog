<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** CRM — Proposta comercial de uma oportunidade. */
class CrmProposal extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'opportunity_id', 'customer_id', 'codigo', 'tipo', 'numero', 'versao', 'data_emissao', 'data_validade',
        'valor', 'descontos', 'vendedor_id', 'memoria_calculo', 'conteudo', 'calc_id', 'document_id', 'status', 'created_by_id',
        // Proposal-Centric (desacoplamento): liberação operacional + projeto + vínculo jurídico (metadado)
        'liberado_por', 'liberado_em', 'liberacao_observacao',
        'bloqueado_por', 'bloqueado_em', 'motivo_bloqueio', 'project_id', 'umbrella_ref',
        // V1.5 — motivo de perda (recusada/perdida/expirada)
        'loss_reason_id', 'motivo_perda_obs', 'perdida_em', 'perdida_por',
        // P-E.2.2 — critério de conclusão da assinatura: todos | um_por_parte
        'assinatura_modo',
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
        'liberado_em'   => 'datetime',
        'bloqueado_em'  => 'datetime',
        'perdida_em'    => 'datetime',
    ];

    // Fluxo COMERCIAL OFICIAL (P-A) — separa análise, aprovação e assinatura:
    //   em_elaboracao → enviada → em_analise → (em_revisao N×) → aprovada →
    //   aguardando_assinatura → assinada → liberada → convertida.
    //   Ramos: reprovada/cancelada/expirada/reativada.
    public const STATUSES = [
        'em_elaboracao', 'enviada', 'em_analise', 'em_negociacao', 'em_revisao',
        'aprovada', 'aguardando_assinatura', 'assinada', 'liberada', 'convertida',
        'reprovada', 'cancelada', 'expirada', 'reativada',
        // legados/arquivados (não usados no fluxo comercial; preservados p/ não quebrar dados antigos):
        'aguardando_liberacao', 'liberado_execucao', 'contrato_gerado', 'assinatura_pendente', 'assinado',
    ];

    /** Colunas oficiais do funil comercial (kanban). */
    public const STATUSES_FUNIL = [
        'em_elaboracao', 'enviada', 'em_analise', 'em_negociacao', 'em_revisao',
        'aprovada', 'aguardando_assinatura', 'assinada', 'liberada', 'convertida',
    ];

    public function opportunity(): BelongsTo { return $this->belongsTo(CrmOpportunity::class, 'opportunity_id'); }
    public function customer(): BelongsTo    { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function vendedor(): BelongsTo    { return $this->belongsTo(User::class, 'vendedor_id'); }
    public function calc(): BelongsTo        { return $this->belongsTo(CrmProposalCalc::class, 'calc_id'); }
    public function document(): BelongsTo    { return $this->belongsTo(Document::class, 'document_id'); }
    public function shares(): HasMany        { return $this->hasMany(CrmProposalShare::class, 'proposal_id'); }
    public function project(): BelongsTo     { return $this->belongsTo(Project::class, 'project_id'); }
    public function liberadoPor(): BelongsTo { return $this->belongsTo(User::class, 'liberado_por'); }
    public function bloqueadoPor(): BelongsTo { return $this->belongsTo(User::class, 'bloqueado_por'); }
    public function releaseChecklist(): HasMany { return $this->hasMany(ContractReleaseChecklistItem::class, 'crm_proposal_id')->orderBy('ordem'); }
    public function participants(): HasMany { return $this->hasMany(CrmProposalParticipant::class, 'crm_proposal_id'); }
    public function sections(): HasMany { return $this->hasMany(CrmProposalSection::class, 'crm_proposal_id')->orderBy('order'); }
    public function lossReason(): BelongsTo { return $this->belongsTo(CrmLossReason::class, 'loss_reason_id'); }

    public function getTotalAttribute(): float
    {
        return round((float) $this->valor - (float) $this->descontos, 2);
    }
}
