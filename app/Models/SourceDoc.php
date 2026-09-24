<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Documentação VIVA de um fonte (estado vigente). Identidade = customer + repo + branch + path
 * (nunca só filename). Aponta para a versão vigente (current_version_id) — não duplica conteúdo.
 * Estados de análise: pending|extracting|analyzing|completed|partial|failed.
 */
class SourceDoc extends Model
{
    protected $fillable = [
        'customer_id', 'source_repo_id', 'owner', 'repository', 'branch', 'path', 'filename',
        'tipo', 'lang', 'size_bytes', 'current_source_sha', 'current_version_id',
        'documentation_json', 'schema_version', 'analysis_status', 'analysis_error', 'attempts',
    ];

    protected $casts = [
        'documentation_json' => 'array',
        'schema_version'     => 'integer',
        'size_bytes'         => 'integer',
        'attempts'           => 'integer',
    ];

    public const STATUSES = ['pending', 'extracting', 'analyzing', 'completed', 'partial', 'failed'];

    public function versions(): HasMany
    {
        return $this->hasMany(SourceDocVersion::class)->orderBy('id');
    }

    /** Versão vigente para a qual o documento vivo aponta. */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SourceDocVersion::class, 'current_version_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sourceRepo(): BelongsTo
    {
        return $this->belongsTo(ClientSourceRepo::class, 'source_repo_id');
    }

    public function fullName(): string
    {
        return "{$this->owner}/{$this->repository}";
    }
}
