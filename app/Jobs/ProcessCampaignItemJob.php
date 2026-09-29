<?php

namespace App\Jobs;

use App\Models\SourceDoc;
use App\Models\SourceSemanticCampaign;
use App\Models\SourceSemanticCampaignItem;
use App\SourceCode\Campaign\CampaignBudgetLedger;
use App\SourceCode\Campaign\CampaignService;
use App\SourceCode\SourceDocPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processa 1 item da campanha (1 source_doc) via pipeline (reuso por blob torna réplicas grátis).
 * Fecha o ledger (settle/release), classifica o resultado, atualiza contadores e trata retry.
 * Fila 'source-doc' (1 worker no homolog) — a concorrência é governada pelo dispatch da campanha.
 */
class ProcessCampaignItemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // retry é no nível da CAMPANHA (backoff próprio), não da fila.

    public function __construct(public int $campaignId, public int $itemId)
    {
        $this->onConnection('database')->onQueue('source-doc');
    }

    public function handle(SourceDocPipeline $pipeline, CampaignBudgetLedger $ledger, CampaignService $svc): void
    {
        $item = SourceSemanticCampaignItem::find($this->itemId);
        $campaign = SourceSemanticCampaign::find($this->campaignId);
        if (! $item || ! $campaign || $item->status !== 'running') {
            return;
        }
        $reserved = (float) $item->estimated_cost_usd;

        // Se pausou/cancelou entre a reserva e a execução → libera reserva e NÃO processa.
        if (! in_array($campaign->status, ['running'], true)) {
            $ledger->release($this->campaignId, $reserved);
            $item->update(['status' => 'pending', 'dispatched_at' => null]);
            return;
        }

        $doc = SourceDoc::with('currentVersion')->find($item->source_doc_id);
        if (! $doc || ! $doc->currentVersion) {
            $ledger->release($this->campaignId, $reserved);
            $item->update(['status' => 'failed', 'last_error_kind' => 'non_retryable:missing_version', 'finished_at' => now()]);
            $svc->bumpCounters($campaign, 'failed', false);
            $svc->dispatchAvailable($campaign->refresh());
            return;
        }

        try {
            $ver = $doc->currentVersion;
            // versão mutável (analyzing/partial/failed) → reprocessa nela; reuso por blob evita IA nas réplicas.
            $newVer = $pipeline->reprocessVersion($ver, true);
            $sem = (array) $newVer->semantic_json;

            $reused = ($sem['strategy'] ?? '') === 'reuse_blob_persistent';
            $exec = (string) ($sem['status'] ?? 'partial');
            $docCompl = $sem['documentary_completeness']['level'] ?? null;
            $missing = is_array($sem['funcoes_trace']['missing'] ?? null) ? count($sem['funcoes_trace']['missing']) : 0;
            $cost = (float) ($sem['usage']['actual_cost_usd'] ?? 0.0);

            // fecha o ledger: reserva → custo real (0 em reuso/skip).
            $ledger->settle($this->campaignId, $reserved, $cost);

            $itemStatus = $reused ? 'reused'
                : ($exec === 'completed' ? 'completed'
                : ($exec === 'skipped_cost_limit' ? 'cost_limit'
                : 'partial'));

            $item->update([
                'status'                   => $itemStatus === 'cost_limit' ? 'skipped' : $itemStatus,
                'execution_status'         => $exec,
                'documentary_completeness' => $docCompl,
                'funcoes_missing'          => $missing,
                'cost_usd'                 => $cost,
                'finished_at'              => now(),
            ]);
            $svc->bumpCounters($campaign, $itemStatus, $reused);
        } catch (\Throwable $e) {
            $ledger->release($this->campaignId, $reserved);
            $kind = $this->classify($e->getMessage());
            $max = (int) config('services.source_doc_ai.campaign_max_attempts', 3);
            if ($kind['retryable'] && (int) $item->attempts < $max) {
                // retry no nível da campanha: volta a pending + reenfileira com backoff exponencial.
                $item->update(['status' => 'pending', 'dispatched_at' => null, 'last_error_kind' => 'retryable:' . $kind['type']]);
                $delay = (int) (5 * (2 ** max(0, (int) $item->attempts)));
                self::dispatch($this->campaignId, $this->itemId)->delay(now()->addSeconds($delay));
                return;
            }
            $item->update(['status' => 'failed', 'last_error_kind' => 'non_retryable:' . $kind['type'], 'finished_at' => now()]);
            $svc->bumpCounters($campaign, 'failed', false);
        }

        // avança a campanha (thresholds + próximo dispatch).
        $svc->maybeAutoPause($campaign->refresh());
        $svc->dispatchAvailable($campaign->refresh());
        $svc->maybeFinish($campaign->refresh());
    }

    /** retryable = transitório (timeout/429/5xx/conexão); non_retryable = determinístico/limite/validação. */
    private function classify(string $msg): array
    {
        $m = mb_strtolower($msg);
        foreach (['timeout' => 'timeout', 'timed out' => 'timeout', '429' => 'rate_limit', 'rate limit' => 'rate_limit',
            'too many requests' => 'rate_limit', '503' => 'provider_5xx', '502' => 'provider_5xx', '500' => 'provider_5xx',
            'connection' => 'connection', 'curl' => 'connection'] as $needle => $type) {
            if (str_contains($m, $needle)) {
                return ['retryable' => true, 'type' => $type];
            }
        }
        return ['retryable' => false, 'type' => 'deterministic'];
    }
}
