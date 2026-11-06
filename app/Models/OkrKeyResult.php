<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Resultado-chave (KR) de um objetivo.
 */
class OkrKeyResult extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;

    public const UNITS = ['quantidade', 'percentual', 'reais', 'horas', 'dias', 'indice', 'custom'];
    public const DIRECTIONS = ['aumentar', 'reduzir', 'manter_faixa'];
    public const AGGREGATIONS = ['acumulativo', 'media', 'posicao_fechamento'];
    public const DISTRIBUTION_MODES = ['auto', 'manual'];
    public const DATA_SOURCE_TYPES = ['manual', 'automatico'];
    public const STATUSES = ['planejado', 'em_andamento', 'concluido', 'cancelado', 'descontinuado'];

    protected $fillable = [
        'company_id', 'okr_objective_id', 'name', 'description', 'responsavel_id',
        'unit', 'unit_label', 'direction', 'aggregation',
        'baseline_value', 'target_value', 'band_min', 'band_max', 'weight',
        'distribution_mode', 'data_source_type', 'data_source_key', 'status', 'created_by',
    ];

    protected $casts = [
        'baseline_value' => 'decimal:4',
        'target_value' => 'decimal:4',
        'band_min' => 'decimal:4',
        'band_max' => 'decimal:4',
        'weight' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    public function monthlyTargets(): HasMany
    {
        return $this->hasMany(OkrMonthlyTarget::class, 'okr_key_result_id');
    }

    public function monthlyResults(): HasMany
    {
        return $this->hasMany(OkrMonthlyResult::class, 'okr_key_result_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }
}
