<?php

namespace App\Jobs;

use App\Services\ConectaMigratedHoursPusher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/** Enfileira o full-sync dos clientes de regra p/ a Conecta (p/ listar inclusive sem movimentação). */
class PushTransferClientsToConectaJob implements ShouldQueue, NotTenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 60;

    public function handle(ConectaMigratedHoursPusher $pusher): void
    {
        $pusher->pushRuleClients();
    }
}
