<?php

namespace App\Notifications;

use App\Models\SkillSurvey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Convite para responder a Pesquisa de Competências interna (colaborador logado).
 * Enviado quando o admin distribui a pesquisa. Assíncrono (fila).
 */
class SkillSurveyInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SkillSurvey $survey)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $base = rtrim((string) config('app.frontend_url', 'https://app.minutor.com.br'), '/');
        $url = "{$base}/competencias/responder";
        $name = $notifiable->name ?? 'colaborador';

        return (new MailMessage)
            ->subject('[Minutor] Pesquisa de Competências — responda a sua')
            ->greeting("Olá, {$name}!")
            ->line('Você foi convidado(a) a responder a **Pesquisa de Competências** (Banco de Competências — ERPSERV).')
            ->line('É rápido: marque seu nível em cada competência da matriz. As respostas são salvas automaticamente e você pode continuar depois.')
            ->action('Responder agora', $url)
            ->line('Ao abrir, faça login no Minutor se ainda não estiver logado. Se não conseguir acessar, procure a equipe administrativa.');
    }
}
