<?php

namespace App\Console\Commands;

use App\Models\SourceSemanticCampaign;
use App\SourceCode\Campaign\CampaignService;
use Illuminate\Console\Command;

/**
 * Avança as campanhas RUNNING: para cada uma, tenta despachar até os slots livres (respeitando
 * reserva de orçamento e thresholds). Roda no schedule:run (cron dedicado) a cada minuto — foreground.
 * Idempotente: se não há slot/orçamento/pending, não faz nada.
 */
class CampaignTickCommand extends Command
{
    protected $signature = 'source-doc:campaign-tick';
    protected $description = 'Avança as campanhas de documentação semântica em execução (dispatch governado)';

    public function handle(CampaignService $svc): int
    {
        $running = SourceSemanticCampaign::where('status', 'running')->get();
        foreach ($running as $c) {
            $svc->dispatchAvailable($c);
            $svc->maybeFinish($c->refresh());
        }
        $this->info('campaign-tick: ' . $running->count() . ' campanha(s) avançada(s)');
        return self::SUCCESS;
    }
}
