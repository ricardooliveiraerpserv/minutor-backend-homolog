<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * C6 — CompileExecution: a execução física (at-most-once por execution_id, imutável). State machine PRÓPRIA
 * (não herda a semântica destrutiva do C5). ArtifactCandidate só nasce quando outcome = succeeded.
 */
class CompileExecution extends Model
{
    protected $table = 'compile_executions';
    protected $guarded = ['id'];
    protected $casts = [
        'diagnostics' => 'array',
        'claimed_at' => 'datetime', 'started_at' => 'datetime',
        'finished_at' => 'datetime', 'deadline_at' => 'datetime',
    ];

    // pending → claimed → running → succeeded | failed | timed_out | cancelled | unknown
    public const ST_PENDING = 'pending';
    public const ST_CLAIMED = 'claimed';
    public const ST_RUNNING = 'running';
    public const ST_SUCCEEDED = 'succeeded';
    public const ST_FAILED = 'failed';
    public const ST_TIMED_OUT = 'timed_out';
    public const ST_CANCELLED = 'cancelled';
    public const ST_UNKNOWN = 'unknown';

    public const TERMINAL = [self::ST_SUCCEEDED, self::ST_FAILED, self::ST_TIMED_OUT, self::ST_CANCELLED, self::ST_UNKNOWN];

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function request()
    {
        return $this->belongsTo(CompileRequest::class, 'compile_request_id');
    }

    public function candidate(): HasOne
    {
        return $this->hasOne(ArtifactCandidate::class, 'compile_execution_id');
    }
}
