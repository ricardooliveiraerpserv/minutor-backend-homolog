<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** CRM — etapa de um funil. */
class CrmPipelineStage extends Model
{
    protected $fillable = ['pipeline_id', 'name', 'ordem', 'is_won', 'is_lost', 'probabilidade', 'cor', 'sla_dias', 'is_inicial', 'ativa', 'regras'];
    protected $casts = ['is_won' => 'boolean', 'is_lost' => 'boolean', 'ordem' => 'integer', 'probabilidade' => 'integer',
        'sla_dias' => 'integer', 'is_inicial' => 'boolean', 'ativa' => 'boolean', 'regras' => 'array'];

    /** Campos exigíveis por etapa (Fase 2 — regras de transição configuráveis). */
    public const REGRAS_DISPONIVEIS = ['valor', 'responsavel', 'proxima_acao', 'contato', 'descricao', 'proposta', 'proposta_aprovada'];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function automations(): HasMany
    {
        return $this->hasMany(CrmStageAutomation::class, 'stage_id');
    }
}
