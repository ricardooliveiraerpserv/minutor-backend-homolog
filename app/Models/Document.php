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

    // ── Fluxo JURÍDICO da assinatura (status_assinatura) — SEPARADO do status operacional ──
    // Item 4: assinatura mora no Document, NÃO no Contract.status. Item 5: máquina de estados.
    public const SIG_NAO_ENVIADO          = 'nao_enviado';
    public const SIG_ENVIADO              = 'enviado';
    public const SIG_ASSINATURA_PENDENTE  = 'assinatura_pendente';
    public const SIG_PARCIALMENTE_ASSINADO = 'parcialmente_assinado';
    public const SIG_ASSINADO             = 'assinado';
    public const SIG_RECUSADO             = 'recusado';
    public const SIG_CANCELADO            = 'cancelado';
    public const SIG_EXPIRADO             = 'expirado';

    public const SIG_STATUSES = [
        self::SIG_NAO_ENVIADO, self::SIG_ENVIADO, self::SIG_ASSINATURA_PENDENTE,
        self::SIG_PARCIALMENTE_ASSINADO, self::SIG_ASSINADO, self::SIG_RECUSADO,
        self::SIG_CANCELADO, self::SIG_EXPIRADO,
    ];

    /** Rótulos PT-BR p/ a UI (Item 8). */
    public const SIG_LABELS = [
        self::SIG_NAO_ENVIADO => 'Não enviado', self::SIG_ENVIADO => 'Enviado',
        self::SIG_ASSINATURA_PENDENTE => 'Assinatura pendente', self::SIG_PARCIALMENTE_ASSINADO => 'Parcialmente assinado',
        self::SIG_ASSINADO => 'Assinado', self::SIG_RECUSADO => 'Recusado',
        self::SIG_CANCELADO => 'Cancelado', self::SIG_EXPIRADO => 'Expirado',
    ];

    /**
     * Transição da máquina de estados da assinatura + auditoria (Itens 5+7).
     * Atualiza status_assinatura (e signed_at quando assinado) e registra o DocumentEvent.
     */
    public function setSignatureStatus(string $status, ?string $eventType = null, array $meta = [], ?int $userId = null): self
    {
        $patch = ['status_assinatura' => $status];
        if ($status === self::SIG_ASSINADO) {
            $patch['signed_at'] = now();
        }
        $this->update($patch);
        if ($eventType) {
            $this->logEvent($eventType, $meta, $userId);
        }
        return $this;
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'attachment_id');
    }

    public function signedAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'signed_attachment_id');
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
