<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Signatário de um Envelope Clicksign (v3). */
class ClicksignSigner extends Model
{
    protected $fillable = [
        'envelope_id', 'crm_proposal_participant_id', 'clicksign_signer_id', 'name', 'email', 'documentation',
        'communicate_by', 'sign_order', 'sign_url', 'status', 'signed_at',
    ];

    protected $casts = [
        'sign_order' => 'integer',
        'signed_at'  => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SIGNED  = 'signed';
    public const STATUS_REFUSED = 'refused';

    public function envelope(): BelongsTo { return $this->belongsTo(ClicksignEnvelope::class, 'envelope_id'); }
}
