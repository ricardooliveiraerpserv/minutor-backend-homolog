<?php

namespace App\Console\Commands;

use App\Http\Controllers\CrmDashboardController;
use Illuminate\Console\Command;

/** Congela o forecast da competência atual (agendado semanalmente). */
class CrmForecastSnapshot extends Command
{
    protected $signature = 'crm:forecast-snapshot';
    protected $description = 'Salva um snapshot semanal do forecast comercial (tendência/slippage)';

    public function handle(CrmDashboardController $controller): int
    {
        $snap = $controller->snapshot();
        $this->info('Snapshot do forecast salvo: ' . $snap->getContent());
        return self::SUCCESS;
    }
}
