<?php

namespace App\Mail;

use App\Models\FollowUp;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FollowUpReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FollowUp $followUp, public string $kind) {}

    public function envelope(): Envelope
    {
        $f = $this->followUp;
        $subject = match ($this->kind) {
            'd5'      => "Follow Up vence em 5 dias: {$f->title}",
            'd3'      => "Follow Up vence em 3 dias: {$f->title}",
            'd1'      => "Seu Follow Up vence amanhã: {$f->title}",
            'due'     => "Seu Follow Up vence hoje: {$f->title}",
            'overdue' => 'Follow Up atrasado há ' . ($f->days_overdue ?? 0) . " dia(s): {$f->title}",
            default   => "Cobrança de Follow Up: {$f->title}",
        };
        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.followups.reminder', with: [
            'f'    => $this->followUp,
            'kind' => $this->kind,
        ]);
    }
}
