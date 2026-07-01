<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

/** Escalonamento comercial — digest de leads que precisam de atenção do gestor. */
class LeadEscalationNotification extends Notification
{
    /** @param array<int,array{empresa:string,responsavel:?string,motivos:string}> $itens */
    public function __construct(public array $itens) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        $m = (new MailMessage)
            ->subject('CRM — ' . count($this->itens) . ' lead(s) precisam de atenção')
            ->greeting('Escalonamento comercial')
            ->line('Os leads abaixo violaram a governança da carteira (sem dono, SLA estourado ou parados sem próxima ação):');
        foreach (array_slice($this->itens, 0, 40) as $i) {
            $m->line('• ' . $i['empresa'] . ' — ' . $i['motivos'] . ($i['responsavel'] ? ' (resp.: ' . $i['responsavel'] . ')' : ' (SEM RESPONSÁVEL)'));
        }
        return $m->action('Abrir Leads no CRM', url('/crm/leads'))
            ->line('Trate ou redistribua para manter a carteira saudável.');
    }
}
