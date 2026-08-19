<?php

namespace App\Console\Commands;

use App\Services\HireNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Mantém o administrativo lembrado das contratações a providenciar a partir da
 * DATA DE PRIMEIRO CONTATO (pop-up na Central/Meu Dia), destacando as ATRASADAS.
 * Enquanto o card não estiver em Finalizado/Pausados, a cobrança continua.
 */
class NotifyPendingHires extends Command
{
    protected $signature = 'contratacao:notify-administrativo {--date= : Data de referência YYYY-MM-DD (p/ teste)}';
    protected $description = 'Avisa o administrativo sobre contratações a providenciar (fixa por data de primeiro contato; atraso se passar)';

    public function handle(): int
    {
        $today = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : now()->startOfDay();
        $r = HireNotifier::sweep($today);
        $this->info("contratações a providenciar: {$r['pending']} (atrasadas: {$r['overdue']})");
        return self::SUCCESS;
    }
}
