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
    // Portal de Propostas — tracking comercial (fonte oficial dos eventos de engajamento):
    public const TYPE_VISUALIZADO = 'visualizado'; // 1ª abertura no portal
    public const TYPE_REVISITADO  = 'revisitado';  // nova sessão de visualização
    public const TYPE_ACEITO      = 'aceito';       // aceite comercial pelo cliente
    public const TYPE_RECUSADO    = 'recusado';     // recusa (com motivo)
    public const TYPE_EXPIRADO    = 'expirado';     // venceu a validade sem decisão
    public const TYPE_CONTRATO_GERADO = 'contrato_gerado'; // contrato emitido a partir da proposta aprovada
    // Jornada JURÍDICA da assinatura eletrônica (Clicksign) — auditoria completa:
    public const TYPE_ASSINATURA_SOLICITADA = 'assinatura_solicitada'; // documento enviado p/ assinatura
    public const TYPE_ASSINATURA_INICIADA   = 'assinatura_iniciada';   // 1º signatário abriu/assinou
    public const TYPE_ASSINATURA_PARCIAL    = 'assinatura_parcial';    // parte dos signatários assinou
    public const TYPE_ASSINATURA_CONCLUIDA  = 'assinatura_concluida';  // todos assinaram (documento assinado)
    public const TYPE_ASSINATURA_RECUSADA   = 'assinatura_recusada';
    public const TYPE_ASSINATURA_CANCELADA  = 'assinatura_cancelada';
    public const TYPE_ASSINATURA_EXPIRADA   = 'assinatura_expirada';
    // Captura dos artefatos assinados (Fase 4.4).
    public const TYPE_PDF_ASSINADO_CAPTURADO = 'pdf_assinado_capturado';
    public const TYPE_CERTIFICADO_CAPTURADO  = 'certificado_capturado';
    public const TYPE_EVIDENCIAS_CAPTURADAS  = 'evidencias_capturadas';

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
