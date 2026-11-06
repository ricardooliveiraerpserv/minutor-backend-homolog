<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Planejamento Anual (ciclo de OKR).
 */
class OkrCycle extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;

    public const STATUSES = ['rascunho', 'em_planejamento', 'aprovado', 'em_execucao', 'encerrado'];

    /** Limiares default do semáforo (aderência %), sobrescritíveis em settings. */
    public const DEFAULT_SETTINGS = ['verde' => 95, 'amarelo' => 80];

    protected $fillable = [
        'company_id', 'year', 'name', 'description', 'responsavel_id',
        'start_date', 'end_date', 'status', 'settings',
        'approved_by', 'approved_at', 'closed_by', 'closed_at', 'created_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'settings' => 'array',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function objectives(): HasMany
    {
        return $this->hasMany(OkrObjective::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Limiares efetivos do semáforo (settings do ciclo ou default). */
    public function thresholds(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings['semaforo'] ?? []);
    }
}
