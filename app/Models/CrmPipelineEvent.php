<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CRM — trilha de auditoria de configuração de pipelines/etapas/automações (Fase 5). */
class CrmPipelineEvent extends Model
{
    public $timestamps = false; // só created_at

    protected $fillable = ['pipeline_id', 'stage_id', 'acao', 'descricao', 'antes', 'depois', 'user_id', 'created_at'];
    protected $casts = ['antes' => 'array', 'depois' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    /** Registra um evento de auditoria de configuração. */
    public static function log(string $acao, ?int $pipelineId, ?int $stageId, ?string $descricao = null, ?array $antes = null, ?array $depois = null): void
    {
        static::create([
            'pipeline_id' => $pipelineId, 'stage_id' => $stageId, 'acao' => $acao,
            'descricao' => $descricao, 'antes' => $antes, 'depois' => $depois,
            'user_id' => auth()->id(), 'created_at' => now(),
        ]);
    }
}
