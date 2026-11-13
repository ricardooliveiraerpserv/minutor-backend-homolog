<?php

namespace App\Mail;

use App\Models\StageDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeliveryApprovalRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public StageDelivery $delivery, public ?string $recipientName = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Aprovação solicitada — {$this->delivery->title}");
    }

    public function content(): Content
    {
        $this->delivery->loadMissing(['stage:id,project_id,name', 'stage.project:id,name,customer_id']);
        $project = $this->delivery->stage?->project;

        // Mensagem do pedido (comentário visível ao cliente gravado junto com o pedido).
        $msg = \App\Models\StageActivityEvent::query()
            ->where('delivery_id', $this->delivery->id)
            ->where('type', \App\Models\StageActivityEvent::TYPE_COMMENT)
            ->when($this->delivery->approval_requested_at, fn ($q) => $q->where('created_at', '>=', $this->delivery->approval_requested_at->copy()->subMinute()))
            ->orderByDesc('id')->get()
            ->first(fn ($e) => in_array(\App\Models\StageActivityEvent::AUDIENCE_CLIENTE, (array) $e->audiences, true));
        $requestedBy = $this->delivery->approval_requested_by
            ? \App\Models\User::find($this->delivery->approval_requested_by)?->name : null;

        $base = rtrim((string) config('app.frontend_url'), '/');
        $url = $project ? "{$base}/portal-cliente/projetos/{$project->id}?atividade={$this->delivery->id}" : $base;

        return new Content(view: 'emails.deliveries.approval-request', with: [
            'd'             => $this->delivery,
            'projectName'   => $project?->name,
            'stageName'     => $this->delivery->stage?->name,
            'recipientName' => $this->recipientName,
            'requestedBy'   => $requestedBy,
            'message'       => is_array($msg?->payload) ? ($msg->payload['text'] ?? null) : null,
            'url'           => $url,
        ]);
    }
}
