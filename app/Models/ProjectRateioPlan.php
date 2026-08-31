<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Período de vigência do rateio de um projeto-servidor (is_rateio). data_fim null = aberto.
 * Períodos são exclusivos no tempo; o RateioHoursService escolhe o período ativo pela data
 * do apontamento e normaliza os pesos dos destinos p/ 100%.
 */
class ProjectRateioPlan extends Model
{
    protected $fillable = ['rateio_project_id', 'data_inicio', 'data_fim', 'position'];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim'    => 'date',
    ];

    public function rateioProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'rateio_project_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ProjectRateioTarget::class, 'plan_id')->orderBy('position');
    }
}
