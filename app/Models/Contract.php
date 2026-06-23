<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id', 'project_name', 'status', 'categoria', 'service_type_id', 'contract_type_id',
        'tipo_faturamento', 'cobra_despesa_cliente', 'limite_despesa',
        'architect_id', 'tipo_alocacao', 'horas_contratadas',
        'valor_projeto', 'valor_hora', 'hora_adicional', 'pct_horas_coordenador', 'horas_coordenacao', 'horas_consultor',
        'expectativa_inicio', 'condicao_pagamento',
        // Contratos recorrentes (gestão de aniversário / reajuste)
        'data_assinatura', 'data_vencimento', 'valor_inicial', 'taxa_reajuste', 'pct_reajuste', 'data_ultimo_reajuste',
        'executivo_conta_id', 'vendedor_id', 'observacoes', 'project_code_preview',
        'project_id', 'parent_project_id', 'generated_at', 'generated_by_id',
        'approved_by_id', 'approved_at', 'created_by_id',
        'kanban_status', 'kanban_coordinator_id', 'kanban_order', 'sustentacao_column',
        // Aditivo: altera um projeto pai/independente (não gera projeto novo)
        'is_aditivo', 'aditivo_project_id', 'aditivo_field', 'aditivo_effective_from', 'aditivo_old_value',
        'aditivo_changes',
        // Assinatura eletrônica (pré-requisitos): Document oficial + rastreabilidade/congelamento da origem
        'contract_document_id', 'crm_proposal_id', 'proposal_version',
        'proposal_document_id', 'proposal_document_version', 'proposal_document_hash', 'proposal_calc_snapshot',
        // Fase 4.1 — liberação/bloqueio operacional + hierarquia de contratos
        'liberado_por', 'liberado_em', 'liberacao_observacao',
        'bloqueado_por', 'bloqueado_em', 'motivo_bloqueio', 'parent_contract_id',
    ];

    protected $casts = [
        'aditivo_changes'        => 'array',
        'proposal_calc_snapshot' => 'array',
        'liberado_em'            => 'datetime',
        'bloqueado_em'           => 'datetime',
        'cobra_despesa_cliente'  => 'boolean',
        'expectativa_inicio'     => 'date:Y-m-d',
        'generated_at'           => 'datetime',
        'approved_at'            => 'datetime',
        'horas_contratadas'      => 'integer',
        'horas_consultor'        => 'integer',
        'valor_projeto'          => 'decimal:2',
        'valor_hora'             => 'decimal:2',
        'data_assinatura'        => 'date:Y-m-d',
        'data_vencimento'        => 'date:Y-m-d',
        'data_ultimo_reajuste'   => 'date:Y-m-d',
        'valor_inicial'          => 'decimal:2',
        'pct_reajuste'           => 'decimal:3',
        'hora_adicional'         => 'decimal:2',
        'pct_horas_coordenador'  => 'decimal:2',
        'horas_coordenacao'      => 'decimal:2',
        'limite_despesa'         => 'decimal:2',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
        'deleted_at'             => 'datetime',
        'is_aditivo'             => 'boolean',
        'aditivo_effective_from' => 'date:Y-m-d',
    ];

    const STATUS_RASCUNHO          = 'rascunho';
    const STATUS_APROVADO          = 'aprovado';
    const STATUS_INICIO_AUTORIZADO = 'inicio_autorizado';
    const STATUS_ATIVO             = 'ativo';
    // Fase 4.1 — fluxo operacional (Clicksign + liberação). Projeção coarse, separada do jurídico.
    const STATUS_EMITIDO               = 'emitido';
    const STATUS_AGUARDANDO_ASSINATURA = 'aguardando_assinatura';
    const STATUS_AGUARDANDO_LIBERACAO  = 'aguardando_liberacao';
    const STATUS_LIBERADO_EXECUCAO     = 'liberado_execucao';
    const STATUS_PROJETO_GERADO        = 'projeto_gerado';

    // Colunas Fase Demanda
    const KANBAN_BACKLOG         = 'backlog';
    const KANBAN_NOVO_PROJETO    = 'novo_projeto';
    const KANBAN_EM_PLANEJAMENTO = 'em_planejamento';
    const KANBAN_EM_VALIDACAO    = 'em_validacao';
    const KANBAN_EM_REVISAO      = 'em_revisao';
    const KANBAN_APROVADO        = 'aprovado';
    // Transição → gera projeto
    const KANBAN_INICIO_AUTORIZADO = 'inicio_autorizado';
    // Colunas Fase Projeto (card fica em alocado + project.status controla sub-coluna)
    const KANBAN_ALOCADO         = 'alocado';
    // Aditivo: card nasce em "Novo Contrato" e só pode ir para a coluna "Aditivos"
    const KANBAN_ADITIVO         = 'aditivo';
    // Campos que um aditivo pode alterar no projeto alvo
    const ADITIVO_FIELDS         = ['valor_hora', 'horas_contratadas', 'valor_projeto'];

    const DEMAND_COLUMNS = [
        self::KANBAN_BACKLOG,
        self::KANBAN_NOVO_PROJETO,
        self::KANBAN_EM_PLANEJAMENTO,
        self::KANBAN_EM_VALIDACAO,
        self::KANBAN_EM_REVISAO,
        self::KANBAN_APROVADO,
    ];

    // Colunas visíveis para cada perfil (demanda)
    const DEMAND_CLIENT_COLUMNS = [
        self::KANBAN_BACKLOG,
        self::KANBAN_NOVO_PROJETO,
        self::KANBAN_EM_VALIDACAO,
        self::KANBAN_APROVADO,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function valueChanges(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\ContractValueChange::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    public function architect(): BelongsTo
    {
        return $this->belongsTo(User::class, 'architect_id');
    }

    public function executivoConta(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executivo_conta_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ContractMessage::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Projeto alvo de um contrato aditivo (pai ou independente). */
    public function aditivoProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'aditivo_project_id');
    }

    /** Document OFICIAL do contrato (document_type=contrato) — o que vai p/ assinatura. */
    public function contractDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'contract_document_id');
    }

    /** Proposta que ORIGINOU o contrato (rastreabilidade). */
    public function crmProposal(): BelongsTo
    {
        return $this->belongsTo(CrmProposal::class, 'crm_proposal_id');
    }

    /** Document (PDF) da PROPOSTA congelado na geração do contrato. */
    public function proposalDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'proposal_document_id');
    }

    /** 1:N — um contrato é guarda-chuva de vários projetos (implantação/treinamento/sustentação/evoluções). */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'contract_id');
    }

    /** Hierarquia: Contrato Principal → Aditivos / Renovação (preserva o original). */
    public function parentContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'parent_contract_id');
    }

    public function aditivos(): HasMany
    {
        return $this->hasMany(Contract::class, 'parent_contract_id');
    }

    /** Envelopes de assinatura (1:N, histórico). */
    public function signatureEnvelopes(): HasMany
    {
        return $this->hasMany(ClicksignEnvelope::class, 'contract_id')->latest('id');
    }

    /** Envelope ATIVO (no máx. 1). */
    public function activeEnvelope(): HasMany
    {
        return $this->hasMany(ClicksignEnvelope::class, 'contract_id')->where('is_active', true);
    }

    /** Itens do Checklist de Liberação deste contrato. */
    public function releaseChecklist(): HasMany
    {
        return $this->hasMany(ContractReleaseChecklistItem::class, 'contract_id')->orderBy('ordem');
    }

    public function liberadoPor(): BelongsTo { return $this->belongsTo(User::class, 'liberado_por'); }
    public function bloqueadoPor(): BelongsTo { return $this->belongsTo(User::class, 'bloqueado_por'); }

    public function contacts(): HasMany
    {
        return $this->hasMany(ContractContact::class);
    }

    /**
     * Anexos do contrato — FASE 11.7 (PR 7b): polimórficos via tabela `attachments`.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(\App\Models\Attachment::class, 'entity_id')
            ->where('attachments.entity_type', 'CONTRACT')
            ->whereNull('attachments.deleted_at');
    }

    public function kanbanLogs(): HasMany
    {
        return $this->hasMany(ContractKanbanLog::class);
    }

    public function kanbanCoordinator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'kanban_coordinator_id');
    }

    public function isKanbanComplete(): bool
    {
        $ctName = strtolower(optional($this->contractType)->name ?? '');
        $ctCode = optional($this->contractType)->code ?? '';

        $isOnDemand = $this->tipo_faturamento === 'on_demand'
            || $ctCode === 'on_demand'
            || $ctName === 'on demand';

        // Cloud e SaaS são mensalidades — não exigem horas contratadas (usam valor_projeto).
        $isMensalidade = in_array($ctCode, ['cloud', 'saas'], true)
            || in_array($ctName, ['cloud', 'saas'], true);

        $skipHoursCheck = $isOnDemand || $isMensalidade;

        return !empty($this->customer_id)
            && !empty($this->contract_type_id)
            && ($skipHoursCheck || $this->horas_contratadas > 0);
    }
}
