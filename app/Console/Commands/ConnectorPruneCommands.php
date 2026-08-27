<?php

namespace App\Console\Commands;

use App\Connector\ConnectorCommandService;
use Illuminate\Console\Command;

/**
 * Connector-3 — poda operacional de comandos terminais além da retenção. A auditoria durável
 * permanece em connector_events (timeline C1); esta poda só limpa a fila operacional.
 */
class ConnectorPruneCommands extends Command
{
    protected $signature = 'connector:prune-commands';

    protected $description = 'Poda comandos terminais do Conector além da janela de retenção';

    public function handle(ConnectorCommandService $svc): int
    {
        $n = $svc->prune();
        $this->info("connector:prune-commands — {$n} comando(s) removido(s)");

        return self::SUCCESS;
    }
}
