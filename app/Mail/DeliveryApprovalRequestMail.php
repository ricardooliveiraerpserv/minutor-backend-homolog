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

    public function __construct(public StageDelivery $delivery) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Aprovação solicitada — {$this->delivery->title}");
    }

    public function content(): Content
    {
        $this->delivery->loadMissing(['stage:id,project_id,name', 'stage.project:id,name']);

        return new Content(view: 'emails.deliveries.approval-request', with: [
            'd'           => $this->delivery,
            'projectName' => $this->delivery->stage?->project?->name,
            'stageName'   => $this->delivery->stage?->name,
        ]);
    }
}
