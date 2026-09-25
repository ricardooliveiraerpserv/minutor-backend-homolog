<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * C6 — ArtifactCandidate: o artefato produzido por uma execução succeeded, identificado por artifact_digest
 * (sha256 calculado on-prem/adapter — bytes NUNCA sobem ao Minutor). NÃO é known_good nem published. Só cruza
 * a fronteira para o C5 via handoff GOVERNADO (register) — C6 nunca promove. Sem dedup por digest.
 */
class ArtifactCandidate extends Model
{
    protected $table = 'artifact_candidates';
    protected $guarded = ['id'];
    protected $casts = ['artifact_metadata' => 'array', 'provenance' => 'array'];

    // Fronteira física do artefato (C6.1 Ajuste A) — descoberta empírica; default unknown.
    public const UNIT_STANDALONE = 'standalone';
    public const UNIT_RPO_APO_FULL = 'rpo_apo_full';
    public const UNIT_RPO_APO_INCREMENTAL = 'rpo_apo_incremental';
    public const UNIT_UNKNOWN = 'unknown';

    // Handoff ao C5 (register) — C6 NÃO publica.
    public const HANDOFF_NONE = 'none';
    public const HANDOFF_REQUESTED = 'requested';
    public const HANDOFF_REGISTERED = 'registered';

    public function execution()
    {
        return $this->belongsTo(CompileExecution::class, 'compile_execution_id');
    }

    public function request()
    {
        return $this->belongsTo(CompileRequest::class, 'compile_request_id');
    }
}
