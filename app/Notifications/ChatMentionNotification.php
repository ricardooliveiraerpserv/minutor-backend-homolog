<?php

namespace App\Notifications;

use App\Notifications\Concerns\HasWorkflowCc;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notificação para a pessoa MARCADA (@) numa mensagem de chat (requisição,
 * contrato ou projeto). Diferente de CardChatMessageNotification (que avisa os
 * envolvidos de "nova mensagem"): aqui o foco é "você foi marcado".
 * Workflow: chat.mention.
 */
class ChatMentionNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use HasWorkflowCc;

    public function __construct(
        public string $cardType,          // 'contract_request' | 'project' | 'contract'
        public string $cardCode,
        public string $cardTitle,
        public string $authorName,        // quem marcou
        public string $authorRole,
        public string $messageExcerpt,
        public string $openUrl,
        public string $cardUrl,
        public string $recipientName = 'você',
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $subject = "[Minutor] Você foi marcado(a) por {$this->authorName} — {$this->cardTitle}";

        return $this->applyCc((new MailMessage)
            ->subject($subject))
            ->view('emails.cards.chat-message', [
                'accent'         => app(\App\Workflows\WorkflowConfigService::class)->accent('chat.mention'),
                'eyebrow'        => 'Você foi marcado(a) no chat',
                'cardType'       => $this->cardType,
                'cardCode'       => $this->cardCode,
                'cardTitle'      => $this->cardTitle,
                'authorName'     => $this->authorName,
                'authorRole'     => $this->authorRole,
                'messageExcerpt' => $this->messageExcerpt,
                'openUrl'        => $this->openUrl,
                'cardUrl'        => $this->cardUrl,
                'recipientName'  => $this->recipientName,
            ]);
    }
}
