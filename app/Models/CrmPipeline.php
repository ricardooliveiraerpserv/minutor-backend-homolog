<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** CRM — funil (pipeline). */
class CrmPipeline extends Model
{
    protected $fillable = ['name', 'code', 'ordem', 'active', 'descricao', 'cor', 'bloqueado', 'tipo', 'arquivado'];
    protected $casts = ['active' => 'boolean', 'ordem' => 'integer', 'bloqueado' => 'boolean', 'arquivado' => 'boolean'];

    public function stages(): HasMany
    {
        return $this->hasMany(CrmPipelineStage::class, 'pipeline_id')->orderBy('ordem');
    }
}
