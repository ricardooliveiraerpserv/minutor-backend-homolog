<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * C6 — CompileRequest: a intenção de compilar uma FONTE (identidade por blob_sha) num ambiente, num modo.
 * Não é execução; a execução (at-most-once) vive em CompileExecution. Compile NÃO publica RPO.
 */
class CompileRequest extends Model
{
    protected $table = 'compile_requests';
    protected $guarded = ['id'];
    protected $casts = ['requested_at' => 'datetime'];

    // Modos (SEM fallback silencioso entre eles).
    public const MODE_FIXTURE = 'fixture';
    public const MODE_SIMULATED = 'simulated';
    public const MODE_LIVE = 'live';

    // Status da REQUEST (o estado autoritativo da execução vive em CompileExecution).
    public const ST_OPEN = 'open';
    public const ST_EXECUTING = 'executing';
    public const ST_COMPLETED = 'completed';
    public const ST_FAILED = 'failed';
    public const ST_CANCELED = 'canceled';

    public function context(): HasOne
    {
        return $this->hasOne(CompileContext::class, 'compile_request_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(CompileExecution::class, 'compile_request_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ArtifactCandidate::class, 'compile_request_id');
    }
}
