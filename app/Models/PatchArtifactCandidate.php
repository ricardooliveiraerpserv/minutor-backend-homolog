<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** PATCH — candidate (base + patches). artifact ≠ known_good ≠ published. Handoff GOVERNADO ao C5. */
class PatchArtifactCandidate extends Model
{
    protected $table = 'patch_artifact_candidates';
    protected $guarded = ['id'];
    protected $casts = ['provenance' => 'array'];

    public const HANDOFF_NONE = 'none';
    public const HANDOFF_REQUESTED = 'requested';
    public const HANDOFF_REGISTERED = 'registered';
}
