<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Envelope Clicksign (API v3). Contrato 1:N envelopes; só 1 ativo por vez. */
class ClicksignEnvelope extends Model
{
    protected $fillable = [
        'contract_id', 'crm_proposal_id', 'document_id', 'document_version', 'is_active', 'environment',
        'clicksign_envelope_id', 'clicksign_document_id', 'status', 'motivo_envio',
        'default_subject', 'locale', 'deadline_at', 'sent_by', 'sent_at', 'finished_at', 'raw_meta',
        'capture_status', 'captured_at', 'capture_error',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'deadline_at' => 'datetime',
        'sent_at'     => 'datetime',
        'finished_at' => 'datetime',
        'captured_at' => 'datetime',
        'raw_meta'    => 'array',
    ];

    // Captura dos artefatos assinados (Fase 4.4).
    public const CAP_PENDENTE   = 'pendente';
    public const CAP_CAPTURANDO = 'capturando';
    public const CAP_CONCLUIDO  = 'concluido';
    public const CAP_FALHA      = 'falha';

    // Estados do envelope (v3).
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_FINISHED  = 'finished';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUSED   = 'refused';
    public const STATUS_DEADLINE  = 'deadline';

    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }
    public function crmProposal(): BelongsTo { return $this->belongsTo(CrmProposal::class, 'crm_proposal_id'); }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function sentBy(): BelongsTo   { return $this->belongsTo(User::class, 'sent_by'); }
    public function signers(): HasMany    { return $this->hasMany(ClicksignSigner::class, 'envelope_id')->orderBy('sign_order'); }
    public function requirements(): HasMany { return $this->hasMany(ClicksignRequirement::class, 'envelope_id'); }
}
