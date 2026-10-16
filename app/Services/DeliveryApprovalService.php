<?php

namespace App\Services;

use App\Mail\DeliveryApprovalRequestMail;
use App\Models\StageActivityEvent;
use App\Models\StageDelivery;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Workflow de aprovação do cliente numa atividade do cronograma.
 *
 * - Abre aprovação PENDENTE quando o card entra em "Aguardando cliente"
 *   (automático via observer) ou via botão "Solicitar aprovação" (manual).
 * - Decide (aprova/reprova) tanto pelo cliente envolvido (Portal) quanto pelo
 *   coordenador/admin (interno). Aprovado → avança pra Homologação; reprovado →
 *   volta pra "Em andamento". O histórico vai pra timeline (StageActivityEvent).
 */
class DeliveryApprovalService
{
    /**
     * Marca a atividade como aguardando aprovação do cliente.
     * `saveQuietly` para nunca recursar no observer.
     */
    public function requestApproval(StageDelivery $delivery, ?User $by, bool $sendEmail = true): void
    {
        $delivery->forceFill([
            'approval_status'       => StageDelivery::APPROVAL_PENDING,
            'approval_requested_at' => now(),
            'approval_requested_by' => $by?->id,
            'approval_decided_at'   => null,
            'approval_decided_by'   => null,
            'approval_note'         => null,
        ])->saveQuietly();

        StageActivityEvent::create([
            'stage_id'      => $delivery->stage_id,
            'delivery_id'   => $delivery->id,
            'actor_user_id' => $by?->id,
            'type'          => StageActivityEvent::TYPE_APPROVAL_REQUESTED,
            'payload'       => array_filter([
                'title'          => $delivery->title,
                'client_user_id' => $delivery->client_user_id,
                'client_email'   => $delivery->client_email,
            ]),
        ]);

        if ($sendEmail) {
            $this->notifyClient($delivery);
        }
    }

    /**
     * Registra o parecer do cliente (ou do interno em nome dele) e move o card.
     */
    public function decide(StageDelivery $delivery, User $by, bool $approved, ?string $note, bool $fromClient): void
    {
        $note = $note !== null ? trim($note) : null;

        $delivery->forceFill([
            'approval_status'     => $approved ? StageDelivery::APPROVAL_APPROVED : StageDelivery::APPROVAL_CHANGES,
            'approval_decided_at' => now(),
            'approval_decided_by' => $by->id,
            'approval_note'       => $note ?: null,
            // Aprovado → Homologação; reprovado/ajuste → volta pra Em andamento.
            'status'              => $approved ? StageDelivery::STATUS_REVIEW : StageDelivery::STATUS_IN_PROGRESS,
        ])->save(); // dispara observer (mudança de status → timeline DELIVERY_MOVED)

        StageActivityEvent::create([
            'stage_id'      => $delivery->stage_id,
            'delivery_id'   => $delivery->id,
            'actor_user_id' => $by->id,
            'type'          => $approved ? StageActivityEvent::TYPE_APPROVAL_APPROVED : StageActivityEvent::TYPE_APPROVAL_REJECTED,
            'payload'       => array_filter([
                'title'       => $delivery->title,
                'note'        => $note ?: null,
                'from_client' => $fromClient,
                'decided_by'  => $by->name,
            ], fn ($v) => $v !== null && $v !== false),
        ]);
    }

    /**
     * Mensagem obrigatória que acompanha o pedido de aprovação — vai pra conversa
     * VISÍVEL ao cliente (audiences = cliente, + extras escolhidos), com anexo opcional.
     */
    public function postApprovalMessage(StageDelivery $delivery, User $by, string $message, ?UploadedFile $file = null, array $extraAudiences = []): void
    {
        $attachmentData = [];
        if ($file) {
            $path = $file->store("stage-attachments/{$delivery->stage_id}", 'public');
            $attachmentData = [
                'attachment_path'          => $path,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime'          => $file->getMimeType(),
                'attachment_size'          => $file->getSize(),
            ];
        }

        $audiences = array_values(array_unique(array_merge([StageActivityEvent::AUDIENCE_CLIENTE], $extraAudiences)));

        StageActivityEvent::create(array_merge([
            'stage_id'      => $delivery->stage_id,
            'delivery_id'   => $delivery->id,
            'actor_user_id' => $by->id,
            'type'          => StageActivityEvent::TYPE_COMMENT,
            'audiences'     => $audiences,
            'payload'       => array_filter([
                'text'             => trim($message) ?: null,
                'approval_request' => true,
            ], fn ($v) => $v !== null && $v !== false),
        ], $attachmentData));
    }

    private function notifyClient(StageDelivery $delivery): void
    {
        if (!$delivery->client_involved) {
            return;
        }

        $email = $delivery->client_email;
        if (!$email && $delivery->client_user_id) {
            $delivery->loadMissing('client:id,name,email');
            $email = $delivery->client?->email;
        }
        if (!$email) {
            return;
        }

        try {
            Mail::to($email)->send(new DeliveryApprovalRequestMail($delivery));
        } catch (\Throwable $e) {
            Log::warning('Falha ao enviar e-mail de aprovação ao cliente', [
                'delivery_id' => $delivery->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
