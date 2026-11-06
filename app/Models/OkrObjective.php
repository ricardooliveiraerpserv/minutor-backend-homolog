<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Objetivo do OKR — corporativo (level=corporativo) ou de área (level=area).
 */
class OkrObjective extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;

    public const LEVELS = ['corporativo', 'area'];
    public const PRIORITIES = ['baixa', 'media', 'alta'];
    public const STATUSES = ['planejado', 'em_andamento', 'concluido', 'cancelado'];

    protected $fillable = [
        'company_id', 'okr_cycle_id', 'level', 'okr_area_id', 'parent_objective_id',
        'title', 'description', 'responsavel_id', 'start_date', 'end_date',
        'priority', 'status', 'weight', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'weight' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(OkrCycle::class, 'okr_cycle_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(OkrArea::class, 'okr_area_id');
    }

    /** Objetivo corporativo ao qual este objetivo de área contribui (desdobramento). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'parent_objective_id');
    }

    /** Objetivos de área que desdobram este objetivo corporativo. */
    public function children(): HasMany
    {
        return $this->hasMany(OkrObjective::class, 'parent_objective_id');
    }

    public function keyResults(): HasMany
    {
        return $this->hasMany(OkrKeyResult::class, 'okr_objective_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function isCorporativo(): bool
    {
        return $this->level === 'corporativo';
    }
}
