<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEvent extends Model
{
    protected $fillable = [
        'document_id', 'sequence_number', 'event_type', 'codigo', 'entity_type', 'entity_id', 'meta', 'triggered_by',
    ];

    protected $casts = [
        'sequence_number' => 'integer',
        'meta'            => 'array',
    ];

    public const TYPE_CRIADO     = 'criado';
    public const TYPE_REGENERADO = 'regenerado';
    public const TYPE_ENVIADO    = 'enviado';
    public const TYPE_BAIXADO    = 'baixado';
    public const TYPE_CANCELADO  = 'cancelado';
    public const TYPE_REATIVADO  = 'reativado';
    public const TYPE_ASSINADO   = 'assinado'; // preparado (não implementado nesta fase)

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
