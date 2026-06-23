<?php

namespace App\Jobs;

use App\Attachments\AttachmentService;
use App\Models\ClicksignEnvelope;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\User;
use App\Services\Clicksign\ClicksignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4.4 — captura assíncrona dos artefatos assinados (PDF assinado + certificado + evidências).
 *
 * Disparado pelo webhook 'finished'. Só aqui o Document vira 'assinado' (consistência: nunca
 * 'assinado' sem signed_attachment_id). 3 tentativas com backoff progressivo; URL temporária
 * expirada é re-obtida a cada tentativa (baixarAssinado re-pede URLs frescas).
 */
class CaptureSignedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    /** Backoff progressivo: 30s, 60s, 120s. */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function __construct(public int $envelopeId) {}

    public function handle(ClicksignService $clicksign, AttachmentService $attachments): void
    {
        $envelope = ClicksignEnvelope::with(['contract', 'signers'])->find($this->envelopeId);

        // ── REGRA CRÍTICA: validações antes de capturar ─────────────────────────
        if (!$envelope) { Log::warning('[capture] envelope inexistente', ['id' => $this->envelopeId]); return; }
        if ($envelope->capture_status === ClicksignEnvelope::CAP_CONCLUIDO) return; // idempotente — já capturado

        // P-E.2.4 — PROPOSTA: baixa o PDF assinado do Clicksign e o torna o documento OFICIAL da proposta (corpo da proposta).
        if (!$envelope->contract && $envelope->crm_proposal_id) {
            $this->capturarProposta($envelope, $clicksign, $attachments);
            return;
        }

        $contract = $envelope->contract;
        $doc = $contract?->contractDocument ?: Document::find($envelope->document_id);
        if (!$contract || !$doc) { $this->abortar($envelope, 'Contrato/documento inexistente'); return; }
        if ((int) $envelope->document_id !== (int) $doc->id) { $this->abortar($envelope, 'Documento divergente'); return; }
        // Envelope SUBSTITUÍDO por versão mais recente? (o contrato aponta p/ outro documento oficial)
        if ((int) $contract->contract_document_id !== (int) $envelope->document_id) {
            $this->abortar($envelope, 'Envelope substituído por versão mais recente — captura ignorada'); return;
        }
        // Envelope precisa estar FINALIZADO válido.
        if ($envelope->status !== ClicksignEnvelope::STATUS_FINISHED) { $this->abortar($envelope, 'Envelope não finalizado'); return; }

        $actor = $envelope->sent_by ? User::find($envelope->sent_by) : null;
        $actor = $actor ?: User::whereIn('type', ['admin', 'administrativo'])->first();
        if (!$actor) { $this->abortar($envelope, 'Sem usuário interno para a captura'); return; }

        $envelope->update(['capture_status' => ClicksignEnvelope::CAP_CAPTURANDO]);

        try {
            $art = $clicksign->baixarAssinado($envelope);

            // ── ORDEM DE EXECUÇÃO ──
            // 1+2+3) PDF assinado → Attachment(signed_pdf) → signed_attachment_id
            if (empty($art['signed']['bytes'])) throw new \RuntimeException('PDF assinado vazio.');
            $signedAtt = $this->guardar($attachments, $actor, $doc->id, 'signed_pdf', $art['signed']);
            $doc->update(['signed_attachment_id' => $signedAtt->id]);
            $doc->logEvent(DocumentEvent::TYPE_PDF_ASSINADO_CAPTURADO, ['attachment_id' => $signedAtt->id, 'envelope_id' => $envelope->id], $actor->id);

            // 4+5) Certificado → Attachment(assinatura_certificado)
            if (!empty($art['certificate']['bytes'])) {
                $certAtt = $this->guardar($attachments, $actor, $doc->id, 'assinatura_certificado', $art['certificate']);
                $doc->logEvent(DocumentEvent::TYPE_CERTIFICADO_CAPTURADO, ['attachment_id' => $certAtt->id], $actor->id);
            }
            // 6+7) Evidências → Attachment(assinatura_evidencias)
            if (!empty($art['evidences']['bytes'])) {
                $evAtt = $this->guardar($attachments, $actor, $doc->id, 'assinatura_evidencias', $art['evidences']);
                $doc->logEvent(DocumentEvent::TYPE_EVIDENCIAS_CAPTURADAS, ['attachment_id' => $evAtt->id], $actor->id);
            }

            // Somente após capturar TUDO: marca assinado + conclui + transição operacional.
            $doc->setSignatureStatus(Document::SIG_ASSINADO, DocumentEvent::TYPE_ASSINATURA_CONCLUIDA, ['envelope_id' => $envelope->id], $actor->id);
            $envelope->update(['capture_status' => ClicksignEnvelope::CAP_CONCLUIDO, 'captured_at' => now(), 'capture_error' => null]);
            if ($contract->status === Contract::STATUS_AGUARDANDO_ASSINATURA) {
                $contract->update(['status' => Contract::STATUS_AGUARDANDO_LIBERACAO]);
            }
            // Auto-check do item 'contrato_assinado' no Checklist de Liberação (Fase 4.5).
            $checklist = app(\App\Services\ContractReleaseChecklistService::class);
            $checklist->instanciar($contract);
            $checklist->sincronizarAssinatura($contract, true);
            ContractEvent::create([
                'contract_id' => $contract->id, 'event_type' => 'assinatura_concluida',
                'to_value' => 'Contrato assinado — artefatos capturados', 'triggered_by' => $actor->id,
                'meta' => ['envelope_id' => $envelope->id, 'signed_attachment_id' => $signedAtt->id],
            ]);
        } catch (\Throwable $e) {
            // Consistência: NÃO marca assinado; mantém status; registra erro; permite retry.
            $envelope->update(['capture_status' => ClicksignEnvelope::CAP_FALHA, 'capture_error' => substr($e->getMessage(), 0, 500)]);
            Log::error('[capture] falha', ['envelope' => $envelope->id, 'tentativa' => $this->attempts(), 'erro' => $e->getMessage()]);
            throw $e; // re-throw → backoff/retry
        }
    }

    /** P-E.2.4 — captura do PDF assinado (Clicksign) como documento oficial da PROPOSTA. */
    private function capturarProposta(ClicksignEnvelope $envelope, ClicksignService $clicksign, AttachmentService $attachments): void
    {
        $envelope->loadMissing('crmProposal');
        $p = $envelope->crmProposal;
        $doc = Document::find($envelope->document_id);
        if (!$p || !$doc) { $this->abortar($envelope, 'Proposta/documento inexistente'); return; }
        if ($envelope->status !== ClicksignEnvelope::STATUS_FINISHED) { $this->abortar($envelope, 'Envelope não finalizado'); return; }
        $actor = ($envelope->sent_by ? User::find($envelope->sent_by) : null) ?: User::whereIn('type', ['admin', 'administrativo'])->first();
        if (!$actor) { $this->abortar($envelope, 'Sem usuário interno para a captura'); return; }

        $envelope->update(['capture_status' => ClicksignEnvelope::CAP_CAPTURANDO]);
        try {
            $art = $clicksign->baixarAssinado($envelope);
            if (empty($art['signed']['bytes'])) throw new \RuntimeException('PDF assinado vazio.');
            // Guarda os artefatos brutos (auditoria).
            $signedAtt = $this->guardar($attachments, $actor, $doc->id, 'signed_pdf_original', $art['signed']);
            $doc->logEvent(DocumentEvent::TYPE_PDF_ASSINADO_CAPTURADO, ['attachment_id' => $signedAtt->id, 'envelope_id' => $envelope->id], $actor->id);
            if (!empty($art['certificate']['bytes'])) {
                $certAtt = $this->guardar($attachments, $actor, $doc->id, 'assinatura_certificado', $art['certificate']);
                $doc->logEvent(DocumentEvent::TYPE_CERTIFICADO_CAPTURADO, ['attachment_id' => $certAtt->id], $actor->id);
            }
            if (!empty($art['evidences']['bytes'])) {
                $evAtt = $this->guardar($attachments, $actor, $doc->id, 'assinatura_evidencias', $art['evidences']);
                $doc->logEvent(DocumentEvent::TYPE_EVIDENCIAS_CAPTURADAS, ['attachment_id' => $evAtt->id], $actor->id);
            }
            // PACOTE FINAL p/ download: proposta assinada + COMPROVANTE OFICIAL Clicksign (log/evidências) + certificado.
            try {
                $merged = \App\Documents\PdfMerger::merge(array_filter([
                    '1-proposta-assinada.pdf' => $art['signed']['bytes'] ?? null,
                    '2-comprovante-clicksign.pdf' => $art['evidences']['bytes'] ?? null,
                    '3-certificado.pdf' => $art['certificate']['bytes'] ?? null,
                ]));
            } catch (\Throwable $e) {
                \Log::warning('[capture][proposta] merge falhou, usando PDF assinado puro: ' . $e->getMessage());
                $merged = $art['signed']['bytes'];
            }
            $pkgAtt = $this->guardar($attachments, $actor, $doc->id, 'signed_pdf', ['filename' => 'proposta-' . ($p->codigo ?: $p->id) . '-assinada-completa.pdf', 'bytes' => $merged, 'mime' => 'application/pdf']);
            $doc->update(['signed_attachment_id' => $pkgAtt->id]); // download = proposta + comprovante Clicksign juntos
            $doc->setSignatureStatus(Document::SIG_ASSINADO, DocumentEvent::TYPE_ASSINATURA_CONCLUIDA, ['envelope_id' => $envelope->id], $actor->id);
            $envelope->update(['capture_status' => ClicksignEnvelope::CAP_CONCLUIDO, 'captured_at' => now(), 'capture_error' => null]);
            // marca a proposta assinada (não regenera o PDF nativo — o oficial agora é o assinado do Clicksign).
            app(\App\Services\ProposalParticipantService::class)->recomputarAssinatura($p->fresh(['participants']));
        } catch (\Throwable $e) {
            $envelope->update(['capture_status' => ClicksignEnvelope::CAP_FALHA, 'capture_error' => substr($e->getMessage(), 0, 500)]);
            Log::error('[capture][proposta] falha', ['envelope' => $envelope->id, 'erro' => $e->getMessage()]);
            throw $e;
        }
    }

    private function guardar(AttachmentService $svc, User $actor, int $documentId, string $category, array $file)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sig_') . '_' . $file['filename'];
        file_put_contents($tmp, $file['bytes']);
        $uploaded = new UploadedFile($tmp, $file['filename'], $file['mime'] ?? null, null, true);
        try {
            return $svc->store($actor, [
                'entity_type' => 'DOCUMENT', 'entity_id' => $documentId,
                'category' => $category, 'file' => $uploaded, 'visibility' => 'internal',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function abortar(ClicksignEnvelope $envelope, string $motivo): void
    {
        $envelope->update(['capture_status' => ClicksignEnvelope::CAP_FALHA, 'capture_error' => $motivo]);
        Log::warning('[capture] abortada', ['envelope' => $envelope->id, 'motivo' => $motivo]);
        if ($doc = Document::find($envelope->document_id)) {
            $doc->logEvent('captura_abortada', ['envelope_id' => $envelope->id, 'motivo' => $motivo], null);
        }
    }
}
