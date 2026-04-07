<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MonitorEmailLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:monitor {--lines=50 : Número de linhas para mostrar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitora logs de email em tempo real';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $lines = $this->option('lines');
        $logFile = storage_path('logs/laravel.log');

        if (!file_exists($logFile)) {
            $this->error('Arquivo de log não encontrado: ' . $logFile);
            return 1;
        }

        $this->info("📋 Monitorando logs de email (últimas {$lines} linhas)...");
        $this->info('🔍 Filtros: [FORGOT PASSWORD], [RESET EMAIL], mail');
        $this->line('');

        // Mostra as últimas linhas relevantes
        $command = "tail -n {$lines} {$logFile} | grep -E 'FORGOT PASSWORD|RESET EMAIL|mail|Mail|smtp|SMTP'";
        $output = shell_exec($command);
        
        if ($output) {
            $this->line($output);
        } else {
            $this->warn('Nenhum log de email encontrado nas últimas ' . $lines . ' linhas.');
        }

        $this->line('');
        $this->info('💡 Para monitorar em tempo real, execute:');
        $this->line('docker-compose exec app tail -f storage/logs/laravel.log | grep -E "FORGOT PASSWORD|RESET EMAIL|mail"');
        
        return 0;
    }
}
