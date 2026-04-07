<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

class TestEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:test {email? : Email para teste} {--type=reset : Tipo de email (reset|welcome)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa o envio de emails de recuperação de senha';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email') ?: $this->ask('Digite o email para teste');
        $type = $this->option('type');

        if (!$email) {
            $this->error('Email é obrigatório!');
            return 1;
        }

        $this->info("Testando envio de email '{$type}' para: {$email}");

        // Verifica se o usuário existe
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error("Usuário com email {$email} não encontrado!");
            $this->info("Usuários disponíveis:");
            User::all()->each(function ($user) {
                $this->line("- {$user->email} ({$user->name})");
            });
            return 1;
        }

        try {
            if ($type === 'welcome') {
                // Testa email de boas-vindas
                $user->notify(new WelcomeNotification('senha123')); // senha temporária de exemplo
                $this->info("✅ Email de boas-vindas enviado com sucesso para {$email}!");
            } else {
                // Testa email de recuperação de senha
                $status = Password::sendResetLink(['email' => $email]);

                if ($status === Password::RESET_LINK_SENT) {
                    $this->info("✅ Email de recuperação enviado com sucesso para {$email}!");
                } else {
                    $this->error("❌ Falha ao enviar email. Status: {$status}");
                }
            }

            $this->info("✅ Verifique a caixa de entrada do email.");

        } catch (\Exception $e) {
            $this->error("❌ Erro ao enviar email: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
        }

        // Mostra configurações de email
        $this->info("\n📧 Configurações de Email:");
        $this->line("MAIL_MAILER: " . config('mail.default'));
        $this->line("MAIL_HOST: " . config('mail.mailers.smtp.host'));
        $this->line("MAIL_PORT: " . config('mail.mailers.smtp.port'));
        $this->line("MAIL_USERNAME: " . config('mail.mailers.smtp.username'));
        $this->line("MAIL_FROM_ADDRESS: " . config('mail.from.address'));
        $this->line("MAIL_FROM_NAME: " . config('mail.from.name'));

        return 0;
    }
}