<?php

namespace App\Jobs;

use App\Services\ConectaMigratedHoursPusher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Enfileira o push (tempo real) dos apontamentos migrados p/ o espelho da Conecta.
 * NotTenantAware: roda no contexto landlord (sem tenant); o pusher carrega withoutGlobalScopes.
 */
class PushMigratedHoursToConectaJob implements ShouldQueue, NotTenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 90;

    /** @param array<int,int> $ids */
    public function __construct(public array $ids) {}

    public function handle(ConectaMigratedHoursPusher $pusher): void
    {
        $pusher->pushIds($this->ids);
    }
}
