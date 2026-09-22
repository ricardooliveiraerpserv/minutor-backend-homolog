<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Convite para um quadro do Kanban do Cliente ("Meus Processos").
 * O link é montado no controller com config('app.kanban_invite_link_base') → aponta
 * SEMPRE para produção (o cliente acessa a rotina em prod) e carrega ?convite=token,
 * que faz a tela aceitar o convite e liberar o acesso ao quadro.
 */
class KanbanBoardInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $boardName,
        public string $link,
        public ?string $inviterName = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $who = $this->inviterName ? ($this->inviterName . ' convidou você') : 'Você foi convidado(a)';

        return (new MailMessage)
            ->subject('Convite para o quadro "' . $this->boardName . '" — ' . config('app.name', 'Minutor'))
            ->greeting('Olá!')
            ->line($who . ' para participar do quadro "' . $this->boardName . '" em Meus Processos.')
            ->line('Clique no botão abaixo para aceitar e acessar a rotina.')
            ->action('Aceitar e acessar', $this->link)
            ->line('Se o botão não funcionar, copie e cole este endereço no navegador:')
            ->line($this->link);
    }
}
