<?php

namespace App\Services\Clicksign;

use App\Models\ClicksignEnvelope;
use App\Models\ClicksignSigner;
use App\Models\ClicksignWebhookEvent;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\Document;
use App\Models\DocumentEvent;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4.3 — processa eventos do webhook Clicksign v3 e atualiza os ESTADOS internos.
 *
 * SEM download de PDF/certificado/evidências, SEM liberação, SEM gate de projeto (fases posteriores).
 * Apenas: status_assinatura (Document) + status operacional (Contract) + status do signatário +
 * auditoria (DocumentEvent + ContractEvent, com resumo — o payload completo fica em clicksign_webhook_events).
 */
class ClicksignWebhookProcessor
{
    // Ações normalizadas a partir do nome do evento v3.
    private const RUNNING   = 'running';
    private const PARTIAL   = 'partial';
    private const FINISHED  = 'finished';
    private const REFUSED   = 'refused';
    private const CANCELLED = 'cancelled';
    private const EXPIRED   = 'expired';

    public function extractEnvelopeId(array $p): ?string
    {
        return $p['envelope']['id']
            ?? (($p['data']['type'] ?? null) === 'envelopes' ? ($p['data']['id'] ?? null) : null)
            ?? $p['data']['envelope_id']
            ?? $p['data']['relationships']['envelope']['data']['id']
            ?? null;
    }

    private function extractSignerId(array $p): ?string
    {
        return $p['signer']['id']
            ?? (($p['data']['type'] ?? null) === 'signers' ? ($p['data']['id'] ?? null) : null)
            ?? null;
    }

    public function eventName(array $p): ?string
    {
        return $p['event']['name'] ?? $p['event']['type'] ?? $p['type'] ?? null;
    }

    private function normalize(array $p): ?string
    {
        $n = strtolower((string) $this->eventName($p));
        if ($n === '') return null;
        if (str_contains($n, 'cancel')) return self::CANCELLED;
        if (str_contains($n, 'deadline') || str_contains($n, 'expir')) return self::EXPIRED;
        if (str_contains($n, 'refus') || str_contains($n, 'reject')) return self::REFUSED;
        if (str_contains($n, 'finish') || str_contains($n, 'close') || str_contains($n, 'complete')) return self::FINISHED;
        if (str_contains($n, 'sign')) return self::PARTIAL; // signer.signed / sign → assinatura parcial
        if (str_contains($n, 'activat') || str_contains($n, 'running') || str_contains($n, 'start')) return self::RUNNING;
        return null;
    }

    public function process(array $payload, ClicksignWebhookEvent $log): void
    {
        $clEnvId = $this->extractEnvelopeId($payload);
        if (!$clEnvId) return;
        $envelope = ClicksignEnvelope::where('clicksign_envelope_id', $clEnvId)->first();
        if (!$envelope) return;

        $action = $this->normalize($payload);
        if (!$action) return;

        DB::transaction(function () use ($payload, $envelope, $action) {
            $contract = $envelope->contract;
            $doc = $contract?->contractDocument ?: Document::find($envelope->document_id);
            $signerId = $this->extractSignerId($payload);
            $signer = $signerId ? $envelope->signers()->where('clicksign_signer_id', $signerId)->first() : null;
            $proposta = $envelope->crm_proposal_id ? $envelope->crmProposal : null;
            $resumo = array_filter([
                'envelope_id'           => $envelope->id,
                'clicksign_envelope_id' => $envelope->clicksign_envelope_id,
                'evento'                => $this->eventName($payload),
                'signatario'            => $signer?->name,
            ]);

            switch ($action) {
                case self::RUNNING:
                    $envelope->update(['status' => ClicksignEnvelope::STATUS_RUNNING]);
                    $this->sig($doc, Document::SIG_ASSINATURA_PENDENTE, DocumentEvent::TYPE_ASSINATURA_SOLICITADA, $resumo);
                    $this->contractEvt($contract, 'assinatura_solicitada', 'Envelope ativo (aguardando assinatura)', $resumo);
                    break;

                case self::PARTIAL:
                    $this->marcarSigner($signer, ClicksignSigner::STATUS_SIGNED);
                    if ($proposta) $this->marcarParticipanteAssinou($signer, $proposta, $doc, $envelope);
                    // só "parcial" se ainda há pendentes; se todos assinaram, o evento finished trata.
                    $pendentes = $envelope->signers()->where('status', ClicksignSigner::STATUS_PENDING)->count();
                    if ($pendentes > 0) {
                        $this->sig($doc, Document::SIG_PARCIALMENTE_ASSINADO, DocumentEvent::TYPE_ASSINATURA_PARCIAL, $resumo);
                        $this->contractEvt($contract, 'assinatura_parcial', 'Assinatura parcial', $resumo);
                    }
                    break;

                case self::FINISHED:
                    if ($proposta) foreach ($envelope->signers as $sg) $this->marcarParticipanteAssinou($sg, $proposta, $doc, $envelope);
                    $envelope->signers()->where('status', ClicksignSigner::STATUS_PENDING)
                        ->update(['status' => ClicksignSigner::STATUS_SIGNED, 'signed_at' => now()]);
                    $envelope->update([
                        'status' => ClicksignEnvelope::STATUS_FINISHED, 'finished_at' => now(), 'is_active' => false,
                        'capture_status' => ClicksignEnvelope::CAP_PENDENTE,
                    ]);
                    // CONSISTÊNCIA: NÃO marca 'assinado' aqui (exige signed_attachment_id). A captura
                    // assíncrona (CaptureSignedDocumentJob, fila documents) baixa os artefatos e só então
                    // marca assinado + transição operacional + assinatura_concluida.
                    \App\Jobs\CaptureSignedDocumentJob::dispatch($envelope->id)
                        ->onQueue(config('documents.queue', 'documents'));
                    break;

                case self::REFUSED:
                    $this->marcarSigner($signer, ClicksignSigner::STATUS_REFUSED, false);
                    if ($proposta) $this->marcarParticipanteSign($signer, 'refused', $proposta, $payload['signer']['refusal_reason'] ?? ($payload['data']['attributes']['refusal_reason'] ?? null));
                    $envelope->update(['status' => ClicksignEnvelope::STATUS_REFUSED, 'finished_at' => now(), 'is_active' => false]);
                    $this->sig($doc, Document::SIG_RECUSADO, DocumentEvent::TYPE_ASSINATURA_RECUSADA, $resumo);
                    $this->voltarParaEmitido($contract);
                    $this->contractEvt($contract, 'assinatura_recusada', 'Assinatura recusada', $resumo);
                    break;

                case self::CANCELLED:
                    if ($proposta) foreach ($envelope->signers as $sg) $this->marcarParticipanteSign($sg, 'cancelled', $proposta);
                    $envelope->update(['status' => ClicksignEnvelope::STATUS_CANCELLED, 'finished_at' => now(), 'is_active' => false]);
                    $this->sig($doc, Document::SIG_CANCELADO, DocumentEvent::TYPE_ASSINATURA_CANCELADA, $resumo);
                    $this->voltarParaEmitido($contract);
                    $this->contractEvt($contract, 'assinatura_cancelada', 'Assinatura cancelada', $resumo);
                    break;

                case self::EXPIRED:
                    if ($proposta) foreach ($envelope->signers->where('status', ClicksignSigner::STATUS_PENDING) as $sg) $this->marcarParticipanteSign($sg, 'expired', $proposta);
                    $envelope->update(['status' => ClicksignEnvelope::STATUS_DEADLINE, 'finished_at' => now(), 'is_active' => false]);
                    $this->sig($doc, Document::SIG_EXPIRADO, DocumentEvent::TYPE_ASSINATURA_EXPIRADA, $resumo);
                    $this->voltarParaEmitido($contract);
                    $this->contractEvt($contract, 'assinatura_expirada', 'Assinatura expirada', $resumo);
                    break;
            }
        });
    }

    private function sig(?Document $doc, string $status, string $eventType, array $meta): void
    {
        if (!$doc) return;
        $doc->setSignatureStatus($status, $eventType, $meta, null);
    }

    /** Recusa/cancelamento/expiração liberam reenvio → contrato volta a "emitido". */
    private function voltarParaEmitido(?Contract $contract): void
    {
        if ($contract && $contract->status === Contract::STATUS_AGUARDANDO_ASSINATURA) {
            $contract->update(['status' => Contract::STATUS_EMITIDO]);
        }
    }

    private function marcarSigner(?ClicksignSigner $signer, string $status, bool $stamp = true): void
    {
        if (!$signer) return;
        $signer->update(['status' => $status, 'signed_at' => ($stamp && $status === ClicksignSigner::STATUS_SIGNED) ? now() : $signer->signed_at]);
    }

    /** P-E.2.0 — reflete a assinatura Clicksign no participante da proposta e recomputa o status. */
    private function marcarParticipanteAssinou(?ClicksignSigner $signer, \App\Models\CrmProposal $p, $doc, ClicksignEnvelope $env): void
    {
        if (!$signer?->crm_proposal_participant_id) return;
        $part = \App\Models\CrmProposalParticipant::find($signer->crm_proposal_participant_id);
        if (!$part || $part->signed_at) return;
        $part->update([
            'signed_at' => now(), 'sign_status' => 'signed', 'sign_status_at' => now(),
            'sign_name' => $signer->name, 'sign_doc_hash' => $doc?->hash,
            'sign_doc_version' => $env->document_version, 'sign_user_agent' => 'Clicksign',
        ]);
        // garante status aprovada antes de recomputar assinatura
        if (in_array($p->status, ['aprovada', 'aguardando_assinatura'], true)) {
            app(\App\Services\ProposalParticipantService::class)->recomputarAssinatura($p->fresh(['participants']));
        }
    }

    /** P-E.2.1 — reflete recusa/expiração/cancelamento da assinatura no participante. */
    private function marcarParticipanteSign(?ClicksignSigner $signer, string $status, \App\Models\CrmProposal $p, ?string $motivo = null): void
    {
        if (!$signer?->crm_proposal_participant_id) return;
        $part = \App\Models\CrmProposalParticipant::find($signer->crm_proposal_participant_id);
        if (!$part || $part->signed_at) return;
        $part->update(['sign_status' => $status, 'sign_status_at' => now(), 'sign_refusal_reason' => $status === 'refused' ? $motivo : null]);
        if ($status === 'refused' && $p->opportunity_id) {
            \App\Models\CrmOpportunityEvent::log((int) $p->opportunity_id, 'note', [
                'to_value' => "{$part->name} RECUSOU a assinatura" . ($motivo ? " — {$motivo}" : ''),
                'meta' => ['kind' => 'assinatura_recusada', 'participant_id' => $part->id],
            ]);
        }
    }

    private function contractEvt(?Contract $contract, string $eventType, string $label, array $meta): void
    {
        if (!$contract) return;
        ContractEvent::create([
            'contract_id'  => $contract->id,
            'event_type'   => $eventType,
            'to_value'     => $label,
            'triggered_by' => null,
            'meta'         => $meta,
        ]);
    }
}
