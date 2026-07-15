<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** CRM — Oportunidade (deal). Empresa = customers (empresa única). */
class CrmOpportunity extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'title', 'descricao', 'customer_id', 'customer_contact_id', 'pipeline_id', 'stage_id', 'responsavel_id', 'tipo',
        'origem', 'lead_source_id',
        'valor', 'status', 'data_abertura', 'previsao_fechamento', 'fechamento_at', 'motivo', 'loss_reason_id',
        'sla_dias', 'campaign_id', 'contract_id', 'renewal_contract_id', 'ultima_interacao_at', 'proxima_acao_at', 'proxima_acao',
        'notas', 'created_by_id',
        // Enriquecimento do card (espelha RD Station): qualificação + mapa de detalhes da negociação.
        'qualificacao', 'detalhes',
        // Previsibilidade: probabilidade manual (%) + motivo da parada + categoria de forecast.
        'probabilidade', 'motivo_parada', 'parada_em', 'forecast_categoria',
    ];

    /** Tipos de oportunidade → cada um define o funil (code do pipeline). */
    public const TIPOS = ['novo_cliente', 'renovacao', 'expansao'];

    protected $casts = [
        'valor'               => 'decimal:2',
        'data_abertura'       => 'date',
        'previsao_fechamento' => 'date',
        'fechamento_at'       => 'datetime',
        'ultima_interacao_at' => 'datetime',
        'proxima_acao_at'     => 'datetime',
        'sla_dias'            => 'integer',
        'detalhes'            => 'array',
        'probabilidade'       => 'integer',
        'parada_em'           => 'datetime',
    ];

    public const STATUSES = ['aberto', 'ganho', 'perdido'];

    public function customer(): BelongsTo    { return $this->belongsTo(Customer::class); }
    public function pipeline(): BelongsTo    { return $this->belongsTo(CrmPipeline::class, 'pipeline_id'); }
    public function stage(): BelongsTo        { return $this->belongsTo(CrmPipelineStage::class, 'stage_id'); }
    public function responsavel(): BelongsTo  { return $this->belongsTo(User::class, 'responsavel_id'); }
    public function contract(): BelongsTo     { return $this->belongsTo(Contract::class); }
    public function renewalContract(): BelongsTo { return $this->belongsTo(Contract::class, 'renewal_contract_id'); }
    public function leadSource(): BelongsTo   { return $this->belongsTo(CrmLeadSource::class, 'lead_source_id'); }
    public function contato(): BelongsTo      { return $this->belongsTo(CustomerContact::class, 'customer_contact_id'); }
    public function lossReason(): BelongsTo   { return $this->belongsTo(CrmLossReason::class, 'loss_reason_id'); }
    public function campaign(): BelongsTo     { return $this->belongsTo(CrmCampaign::class, 'campaign_id'); }
    public function tasks(): HasMany          { return $this->hasMany(CrmTask::class, 'opportunity_id'); }
    public function events(): HasMany         { return $this->hasMany(CrmOpportunityEvent::class, 'opportunity_id'); }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(CrmProduct::class, 'crm_opportunity_products', 'opportunity_id', 'crm_product_id')
            ->withPivot(['quantidade', 'valor'])->withTimestamps();
    }
}
