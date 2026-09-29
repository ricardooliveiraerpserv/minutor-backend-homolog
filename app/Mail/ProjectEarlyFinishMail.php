<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectEarlyFinishMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $data) {}

    public function envelope(): Envelope
    {
        $p = $this->data['project'];
        return new Envelope(subject: "Saving — {$p->name} finalizado {$this->data['days_early']} dia(s) antes do prazo");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.projects.early-finish-saving', with: ['d' => $this->data]);
    }
}
