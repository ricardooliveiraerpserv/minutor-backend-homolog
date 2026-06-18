<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plataforma de Documentos — artefato renderizado (PDF congelado de uma versão), genérico.
 * A entidade de NEGÓCIO (Proposta/Contrato/Fechamento) é dona dos dados; o Document é o artefato.
 */
class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'document_type', 'entity_type', 'entity_id', 'codigo', 'versao', 'status',
        'template', 'renderer', 'render_ms', 'hash', 'params_snapshot', 'attachment_id',
        'generated_at', 'generated_by',
        'status_assinatura', 'signed_at', 'signed_attachment_id',
    ];

    protected $casts = [
        'entity_id'       => 'integer',
        'versao'          => 'integer',
        'params_snapshot' => 'array',
        'generated_at'    => 'datetime',
        'signed_at'       => 'datetime',
    ];

    public const STATUSES = [
        'em_elaboracao', 'gerado', 'enviada', 'em_negociacao', 'aprovada',
        'reprovada', 'cancelada', 'expirada', 'reativada', 'convertida',
    ];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'attachment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderByDesc('sequence_number');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** Registra um evento de auditoria com sequence atômico por documento. */
    public function logEvent(string $type, array $meta = [], ?int $userId = null): DocumentEvent
    {
        $seq = (int) $this->events()->max('sequence_number') + 1;
        return $this->events()->create([
            'sequence_number' => $seq,
            'event_type'      => $type,
            'meta'            => $meta ?: null,
            'triggered_by'    => $userId ?? auth()->id(),
        ]);
    }
}
