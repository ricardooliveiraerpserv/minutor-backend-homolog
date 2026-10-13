<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liberação de faturamento de um contrato On Demand (projeto pai) numa competência.
 * A existência da linha (project_id, year_month) = liberado para faturar.
 */
class OnDemandBillingRelease extends Model
{
    protected $fillable = [
        'project_id',
        'year_month',
        'released_at',
        'released_by',
        'reason',
    ];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * Mapa project_id => registro de liberação para a competência (com releasedBy carregado).
     *
     * @return array<int, \App\Models\OnDemandBillingRelease>
     */
    public static function mapFor(string $yearMonth): array
    {
        return static::where('year_month', $yearMonth)
            ->with('releasedBy:id,name')
            ->get()
            ->keyBy('project_id')
            ->all();
    }
}
