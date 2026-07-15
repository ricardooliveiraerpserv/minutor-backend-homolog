<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** CRM — funil (pipeline). */
class CrmPipeline extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = ['name', 'code', 'ordem', 'active', 'descricao', 'cor', 'bloqueado', 'tipo', 'arquivado', 'tipos_empresa'];
    protected $casts = ['active' => 'boolean', 'ordem' => 'integer', 'bloqueado' => 'boolean', 'arquivado' => 'boolean', 'tipos_empresa' => 'array'];

    public function stages(): HasMany
    {
        return $this->hasMany(CrmPipelineStage::class, 'pipeline_id')->orderBy('ordem');
    }
}
