<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentService;
use App\Models\Document;
use App\Models\DocumentEvent;
use Illuminate\Http\Request;

/**
 * Plataforma de Documentos — endpoints genéricos (não comerciais).
 * Download serve o PDF CONGELADO da versão (sem re-render) e registra auditoria.
 */
class DocumentController extends Controller
{
    public function __construct(private AttachmentService $attachments) {}

    public function download(Request $request, Document $document)
    {
        if (!$document->attachment_id && !$document->signed_attachment_id) {
            return response()->json(['message' => 'Documento sem PDF gerado.'], 404);
        }
        // Se assinado, baixa o PACOTE FINAL (proposta assinada + comprovante oficial Clicksign).
        $att = ($document->signed_attachment_id ? $document->signedAttachment : null) ?: $document->attachment;
        $stream = $this->attachments->downloadStream($att, $request->user(), $request);
        $document->logEvent(DocumentEvent::TYPE_BAIXADO, [
            'versao' => $document->versao, 'attachment_id' => $att->id,
        ], $request->user()->id);
        return $stream;
    }
}
