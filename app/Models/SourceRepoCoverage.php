<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central de Fontes — C3.5. Snapshot/checkpoint de cobertura por repo cadastrado.
 */
class SourceRepoCoverage extends Model
{
    protected $table = 'source_repo_coverage';
    protected $primaryKey = 'source_repo_id';
    public $incrementing = false;

    protected $fillable = [
        'source_repo_id', 'customer_id', 'owner', 'repository', 'branch',
        'scan_status', 'scan_started_at', 'scan_finished_at', 'last_error', 'last_scan_cursor',
        'github_files', 'eligible_source_files', 'new_files', 'unchanged_files', 'changed_files', 'ignored_files',
        'cataloged', 'deterministic', 'semantic', 'indexed', 'last_synced_at',
    ];

    protected $casts = [
        'scan_started_at'  => 'datetime',
        'scan_finished_at' => 'datetime',
        'last_synced_at'   => 'datetime',
    ];

    public const STATUSES = ['pending', 'running', 'completed', 'partial', 'failed', 'rate_limited'];

    public function sourceRepo(): BelongsTo
    {
        return $this->belongsTo(ClientSourceRepo::class, 'source_repo_id');
    }
}
