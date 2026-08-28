<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PATCH — execução física (P2). State machine própria; marcadores de journal p/ prova causal. */
class PatchExecution extends Model
{
    protected $table = 'patch_executions';
    protected $guarded = ['id'];
    protected $casts = [
        'diagnostics' => 'array',
        'execution_committed_at' => 'datetime', 'base_verified_at' => 'datetime',
        'patch_effect_started_at' => 'datetime', 'patch_effect_committed_at' => 'datetime',
        'artifact_verified_at' => 'datetime', 'finished_at' => 'datetime', 'deadline_at' => 'datetime',
    ];

    public const ST_PENDING = 'pending';
    public const ST_CLAIMED = 'claimed';
    public const ST_PREPARATION = 'preparation';
    public const ST_BASE_VERIFIED = 'base_verified';
    public const ST_PATCH_EFFECT_STARTED = 'patch_effect_started';
    public const ST_PATCH_EFFECT_COMMITTED = 'patch_effect_committed';
    public const ST_ARTIFACT_VERIFIED = 'artifact_verified';
    public const ST_CANDIDATE = 'candidate';
    public const ST_FAILED = 'failed';
    public const ST_PARTIAL = 'partial';
    public const ST_INDETERMINATE = 'indeterminate';
    public const ST_CONTRADICTED = 'contradicted';
    public const ST_CANCELLED = 'cancelled';
}
