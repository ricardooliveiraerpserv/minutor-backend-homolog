<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CRM — perfil empresarial 1:1 da empresa (customers). */
class CustomerCrmProfile extends Model
{
    protected $primaryKey = 'customer_id';
    public $incrementing = false;

    protected $fillable = [
        'customer_id', 'region', 'segment', 'porte',
        'faturamento_estimado', 'num_funcionarios', 'erp_atual', 'indicacao',
        // Camada de Leads / qualificação
        'lead_source_id', 'qualification_stage_id', 'observacoes', 'valor_potencial',
        'previsao_fechamento', 'previsao_competencia',
        'ultima_interacao_at', 'proxima_acao_at', 'proxima_acao',
        'lead_created_at', 'qualified_at', 'lost_at', 'lost_reason',
        // Vínculo com o lead-projeto na árvore de Investimento Comercial (custo de aquisição)
        'investimento_project_id',
    ];

    protected $casts = [
        'faturamento_estimado' => 'decimal:2',
        'valor_potencial'      => 'decimal:2',
        'previsao_fechamento'  => 'date',
        'num_funcionarios'     => 'integer',
        'ultima_interacao_at'  => 'datetime',
        'proxima_acao_at'      => 'date',
        'lead_created_at'      => 'datetime',
        'qualified_at'         => 'datetime',
        'lost_at'              => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(CrmLeadSource::class, 'lead_source_id');
    }

    public function qualificationStage(): BelongsTo
    {
        return $this->belongsTo(CrmPipelineStage::class, 'qualification_stage_id');
    }
}
