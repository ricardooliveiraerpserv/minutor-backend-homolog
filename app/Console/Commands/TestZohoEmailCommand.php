<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;

class TestZohoEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zoho:test {email} {--service=mail : Serviço a usar (mail|zepto)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa configurações de email da Zoho (Mail SMTP ou ZeptoMail)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $service = $this->option('service');

        $this->info("🧪 Testando envio de email via Zoho {$service} para: {$email}");
        
        if ($service === 'zepto') {
            $this->testZeptoMail($email);
        } else {
            $this->testZohoMail($email);
        }
    }

    private function testZohoMail($email)
    {
        $this->info("📧 Testando Zoho Mail SMTP...");
        
        // Configurações atuais
        $this->line("📋 Configurações atuais:");
        $this->line("  MAIL_HOST: " . config('mail.mailers.smtp.host'));
        $this->line("  MAIL_PORT: " . config('mail.mailers.smtp.port'));
        $this->line("  MAIL_USERNAME: " . config('mail.mailers.smtp.username'));
        $this->line("  MAIL_ENCRYPTION: " . config('mail.mailers.smtp.encryption'));
        
        try {
            Mail::raw('Teste de email via Zoho Mail SMTP do Laravel', function ($message) use ($email) {
                $message->to($email)
                        ->subject('Teste Zoho Mail - Laravel')
                        ->from(config('mail.from.address'), config('mail.from.name'));
            });
            
            $this->info("✅ Email enviado com sucesso via Zoho Mail!");
        } catch (\Exception $e) {
            $this->error("❌ Erro ao enviar via Zoho Mail: " . $e->getMessage());
        }
    }

    private function testZeptoMail($email)
    {
        $this->info("📧 Testando ZeptoMail...");
        
        // Temporariamente alterar configurações para ZeptoMail
        Config::set('mail.mailers.smtp.host', 'smtp.zeptomail.com');
        Config::set('mail.mailers.smtp.port', 587);
        Config::set('mail.mailers.smtp.encryption', 'tls');
        
        $this->line("📋 Configurações ZeptoMail:");
        $this->line("  MAIL_HOST: smtp.zeptomail.com");
        $this->line("  MAIL_PORT: 587");
        $this->line("  MAIL_ENCRYPTION: tls");
        
        try {
            Mail::raw('Teste de email via ZeptoMail do Laravel', function ($message) use ($email) {
                $message->to($email)
                        ->subject('Teste ZeptoMail - Laravel')
                        ->from(config('mail.from.address'), config('mail.from.name'));
            });
            
            $this->info("✅ Email enviado com sucesso via ZeptoMail!");
            $this->info("💡 Para usar ZeptoMail permanentemente, altere MAIL_HOST para smtp.zeptomail.com no .env");
        } catch (\Exception $e) {
            $this->error("❌ Erro ao enviar via ZeptoMail: " . $e->getMessage());
            $this->warn("⚠️  Verifique se as credenciais são válidas para ZeptoMail ou se precisa de configuração específica");
        }
    }
}
