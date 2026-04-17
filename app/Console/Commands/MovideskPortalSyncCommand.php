<?php

namespace App\Console\Commands;

use App\Services\MovideskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MovideskPortalSyncCommand extends Command
{
    protected $signature   = 'movidesk:portal-sync';
    protected $description = 'Sincroniza tickets do Movidesk para o Portal de Sustentação';

    public function handle(MovideskService $service): int
    {
        $this->info('[MOVIDESK PORTAL] Iniciando sync...');
        Log::info('[MOVIDESK PORTAL] Iniciando sync');

        $tickets = $service->fetchPortalTickets();
        $count   = count($tickets);

        $this->info("[MOVIDESK PORTAL] {$count} tickets encontrados — salvando...");

        $saved = 0;
        foreach ($tickets as $ticket) {
            $service->saveTicketForPortal($ticket);
            $saved++;
        }

        $this->info("[MOVIDESK PORTAL] Sync concluído: {$saved} tickets processados");
        Log::info('[MOVIDESK PORTAL] Sync concluído', ['count' => $saved]);

        return self::SUCCESS;
    }
}
