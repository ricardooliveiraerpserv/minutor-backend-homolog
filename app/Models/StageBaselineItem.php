<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot CONGELADO de uma atividade/etapa no momento da baseline: datas e horas
 * planejadas que NÃO mudam quando o cronograma é replanejado. É a referência do PV/EV.
 */
class StageBaselineItem extends Model
{
    protected $fillable = [
        'project_baseline_id',
        'stage_id',
        'stage_delivery_id',
        'title',
        'planned_start_at',
        'planned_end_at',
        'planned_hours',
    ];

    protected $casts = [
        'planned_start_at' => 'date:Y-m-d',
        'planned_end_at'   => 'date:Y-m-d',
        'planned_hours'    => 'decimal:2',
    ];

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(ProjectBaseline::class, 'project_baseline_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(StageDelivery::class, 'stage_delivery_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'stage_id');
    }
}
