<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Requisito (Requirement) Clicksign v3 — liga signatário × ação × autenticação. */
class ClicksignRequirement extends Model
{
    protected $fillable = [
        'envelope_id', 'signer_id', 'clicksign_requirement_id', 'action', 'auth', 'role',
    ];

    public function envelope(): BelongsTo { return $this->belongsTo(ClicksignEnvelope::class, 'envelope_id'); }
    public function signer(): BelongsTo   { return $this->belongsTo(ClicksignSigner::class, 'signer_id'); }
}
