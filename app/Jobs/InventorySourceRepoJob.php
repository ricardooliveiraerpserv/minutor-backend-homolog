<?php

namespace App\Jobs;

use App\Models\ClientSourceRepo;
use App\SourceCode\SourceDocInventory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Central de Fontes — C3.5. Varre 1 repo de forma assíncrona (fila source-doc). Idempotente/
 * retomável; determinístico (IA zero). Re-enfileira nada — a retomada é por diff/cursor no próximo run.
 */
class InventorySourceRepoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900; // varredura de repo grande

    public function __construct(public int $repoId, public int $batch = 0)
    {
        $this->onConnection('database')->onQueue('source-doc');
    }

    public function handle(SourceDocInventory $inv): void
    {
        $repo = ClientSourceRepo::find($this->repoId);
        if ($repo && $repo->active) {
            $inv->scanRepo($repo, $this->batch);
        }
    }
}
