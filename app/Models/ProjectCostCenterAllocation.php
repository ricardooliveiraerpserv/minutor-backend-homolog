<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha de rateio de um PROJETO num CENTRO DE CUSTO (percentual do valor total do projeto).
 * O valor em R$ é derivado (valor total do projeto × percentual / 100), não é gravado.
 */
class ProjectCostCenterAllocation extends Model
{
    protected $fillable = ['project_id', 'cost_center_id', 'percentual', 'position'];

    protected $casts = ['percentual' => 'decimal:2'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
