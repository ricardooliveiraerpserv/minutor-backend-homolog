<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo híbrido de uma Análise de Qualidade (CodeAnalysis) com uma VERSÃO de fonte da Central.
 * Guarda só o vínculo/negócio + score resumido + referência ao job externo — os findings
 * detalhados vivem no serviço CodeAnalysis (autoridade técnica).
 */
class SourceDocQualityAnalysis extends Model
{
    protected $table = 'source_doc_quality_analyses';

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    public const INFLIGHT = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    protected $fillable = [
        'source_doc_id', 'source_doc_version_id', 'source_blob_sha',
        'external_job_id', 'status',
        'score', 'grade', 'risk', 'n_critical', 'n_warnings', 'n_recommendations', 'n_findings',
        'engine', 'engine_version', 'rules_version',
        'requested_by', 'requested_at', 'started_at', 'completed_at', 'failed_at',
        'error_code', 'error_message',
    ];

    protected $casts = [
        'score'             => 'integer',
        'n_critical'        => 'integer',
        'n_warnings'        => 'integer',
        'n_recommendations' => 'integer',
        'n_findings'        => 'integer',
        'requested_at'      => 'datetime',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'failed_at'         => 'datetime',
    ];

    public function sourceDoc(): BelongsTo
    {
        return $this->belongsTo(SourceDoc::class, 'source_doc_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SourceDocVersion::class, 'source_doc_version_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isInflight(): bool
    {
        return in_array($this->status, self::INFLIGHT, true);
    }

    /** A análise pertence à versão vigente do fonte? (senão, está "outdated"/stale) */
    public function matchesBlob(?string $currentBlobSha): bool
    {
        return $currentBlobSha !== null
            && $this->source_blob_sha !== null
            && hash_equals($this->source_blob_sha, $currentBlobSha);
    }
}
