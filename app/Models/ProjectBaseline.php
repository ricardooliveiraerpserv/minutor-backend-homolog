<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Linha de base (baseline) congelada do cronograma de um projeto — fundação do EVM.
 * Versionada: várias baselines por projeto; `is_current` marca a que alimenta os indicadores.
 * As datas/horas ficam nos {@see StageBaselineItem} (snapshot imutável).
 */
class ProjectBaseline extends Model
{
    protected $fillable = [
        'project_id',
        'label',
        'frozen_at',
        'frozen_by',
        'planned_hours_total',
        'planned_cost_total',
        'notes',
        'is_current',
    ];

    protected $casts = [
        'frozen_at'           => 'datetime',
        'planned_hours_total' => 'decimal:2',
        'planned_cost_total'  => 'decimal:2',
        'is_current'          => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function frozenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'frozen_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StageBaselineItem::class);
    }
}
