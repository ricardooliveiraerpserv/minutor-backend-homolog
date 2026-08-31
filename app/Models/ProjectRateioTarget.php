<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Destino do rateio de horas de um projeto-servidor (is_rateio): % padrão que cada
 * projeto de destino recebe das horas apontadas no projeto de rateio.
 */
class ProjectRateioTarget extends Model
{
    protected $fillable = ['rateio_project_id', 'target_project_id', 'percentual', 'position'];

    protected $casts = ['percentual' => 'decimal:2'];

    public function rateioProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'rateio_project_id');
    }

    public function targetProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'target_project_id');
    }
}
