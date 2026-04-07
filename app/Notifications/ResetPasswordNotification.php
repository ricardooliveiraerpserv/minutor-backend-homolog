<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends BaseResetPassword
{

    public $token;

    /**
     * Create a new notification instance.
     */
    public function __construct($token)
    {
        $this->token = $token;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $resetUrl = $frontendUrl . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        \Log::info('📧 [RESET EMAIL] Construindo email de recuperação:', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->getEmailForPasswordReset(),
            'frontend_url' => $frontendUrl,
            'reset_url' => $resetUrl,
            'token_prefix' => substr($this->token, 0, 10) . '...'
        ]);

        try {
            $mailMessage = (new MailMessage)
                ->subject('Redefinir sua senha')
                ->view('emails.auth.reset-password', [
                    'user' => $notifiable,
                    'resetUrl' => $resetUrl,
                    'token' => $this->token,
                    'validMinutes' => config('auth.passwords.users.expire', 60)
                ]);

            \Log::info('✅ [RESET EMAIL] Email construído com sucesso');
            return $mailMessage;
        } catch (\Exception $e) {
            \Log::error('🚨 [RESET EMAIL] Erro ao construir email:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
