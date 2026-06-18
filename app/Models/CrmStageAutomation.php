<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CRM — automação configurável de etapa (Fase 3). */
class CrmStageAutomation extends Model
{
    protected $fillable = ['stage_id', 'evento', 'tipo', 'config', 'ordem', 'ativa'];
    protected $casts = ['config' => 'array', 'ativa' => 'boolean', 'ordem' => 'integer'];

    /** Tipos suportados (cada um = um handler no StageAutomationRunner). */
    public const TIPOS = [
        'criar_tarefa', 'alterar_status_empresa', 'enviar_email',
        'notificar', 'gerar_proposta', 'gerar_contrato', 'webhook',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmPipelineStage::class, 'stage_id');
    }
}
