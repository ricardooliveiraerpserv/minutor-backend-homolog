<?php

namespace App\Console\Commands;

use App\Connector\ConnectorCommandService;
use Illuminate\Console\Command;

/**
 * Connector-3 — reaper de comandos: leases perdidos → re-enfileira (≤ max_attempts) ou expira;
 * queued com TTL vencido → expira. Backstop do scheduler; o caminho quente já reaper LAZY no poll/list.
 */
class ConnectorReapCommands extends Command
{
    protected $signature = 'connector:reap-commands';

    protected $description = 'Reaper de comandos do Conector (leases perdidos / TTL vencido)';

    public function handle(ConnectorCommandService $svc): int
    {
        $n = $svc->reapAll();
        $this->info("connector:reap-commands — {$n} ambiente(s) varrido(s)");

        return self::SUCCESS;
    }
}
