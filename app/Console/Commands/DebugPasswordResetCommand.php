<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;

class DebugPasswordResetCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'debug:password-reset {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug diferenças entre Mail::raw e Password::sendResetLink';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        
        $this->info("🔍 Debugging Password Reset vs Mail para: {$email}");
        $this->line("");
        
        // 1. Teste Mail::raw (que funciona)
        $this->info("1️⃣ Testando Mail::raw (que funciona):");
        try {
            Mail::raw('Teste Mail::raw', function ($message) use ($email) {
                $message->to($email)
                        ->subject('Teste Mail Raw')
                        ->from(config('mail.from.address'), config('mail.from.name'));
            });
            $this->info("✅ Mail::raw enviado com sucesso!");
        } catch (\Exception $e) {
            $this->error("❌ Mail::raw falhou: " . $e->getMessage());
        }
        
        $this->line("");
        
        // 2. Teste direto da notificação
        $this->info("2️⃣ Testando notificação direta:");
        $user = User::where('email', $email)->first();
        if ($user) {
            try {
                $user->notify(new ResetPasswordNotification('test-token-123'));
                $this->info("✅ Notificação direta enviada!");
            } catch (\Exception $e) {
                $this->error("❌ Notificação direta falhou: " . $e->getMessage());
            }
        } else {
            $this->warn("⚠️ Usuário não encontrado para teste de notificação");
        }
        
        $this->line("");
        
        // 3. Configurações
        $this->info("3️⃣ Configurações:");
        $this->line("Password Config:");
        $passwordConfig = config('auth.passwords.users');
        foreach ($passwordConfig as $key => $value) {
            $this->line("  {$key}: {$value}");
        }
        
        $this->line("");
        $this->line("Mail Config:");
        $this->line("  from.address: " . config('mail.from.address'));
        $this->line("  from.name: " . config('mail.from.name'));
        $this->line("  default: " . config('mail.default'));
        $this->line("  mailer: " . config('mail.mailers.smtp.host'));
        
        $this->line("");
        
        // 4. Teste Password::sendResetLink (que não entrega)
        $this->info("4️⃣ Testando Password::sendResetLink (problema):");
        try {
            $status = Password::sendResetLink(['email' => $email]);
            $this->line("Status retornado: {$status}");
            
            if ($status === Password::RESET_LINK_SENT) {
                $this->info("✅ Password::sendResetLink reportou sucesso!");
            } else {
                $this->error("❌ Password::sendResetLink falhou com status: {$status}");
            }
        } catch (\Exception $e) {
            $this->error("❌ Password::sendResetLink exception: " . $e->getMessage());
        }
        
        return 0;
    }
}
