<?php

namespace App\SourceCode\Campaign;

use App\Jobs\ProcessCampaignItemJob;
use App\Models\SourceSemanticCampaign;
use App\Models\SourceSemanticCampaignEvent;
use App\Models\SourceSemanticCampaignItem;
use Illuminate\Support\Facades\DB;

/**
 * Orquestra a campanha: estados (start/pause/resume/cancel), DISPATCH governado (batch lógico ≠
 * dispatch real; só até available slots — ajuste #2), contadores, auto-pause por orçamento/thresholds.
 */
class CampaignService
{
    public function __construct(private CampaignBudgetLedger $ledger)
    {
    }

    // ── estados ──────────────────────────────────────────────────────────────
    public function start(SourceSemanticCampaign $c, ?int $userId): void
    {
        if (! in_array($c->status, ['ready', 'paused'], true)) {
            return;
        }
        $c->update(['status' => 'running', 'pause_reason' => null, 'started_at' => $c->started_at ?? now(), 'paused_at' => null]);
        $this->event($c, $userId, 'started');
        $this->dispatchAvailable($c);
    }

    public function pause(SourceSemanticCampaign $c, ?int $userId, string $reason = 'manual'): void
    {
        if ($c->status !== 'running') {
            return;
        }
        // Pausar = não despacha NOVOS; jobs já running terminam; itens pending permanecem.
        $c->update(['status' => 'paused', 'pause_reason' => $reason, 'paused_at' => now()]);
        $this->event($c, $userId, $reason === 'manual' ? 'paused' : 'auto_paused', ['reason' => $reason]);
    }

    public function resume(SourceSemanticCampaign $c, ?int $userId): void
    {
        if ($c->status !== 'paused') {
            return;
        }
        $c->update(['status' => 'running', 'pause_reason' => null, 'paused_at' => null]);
        $this->event($c, $userId, 'resumed');
        $this->dispatchAvailable($c);
    }

    public function cancel(SourceSemanticCampaign $c, ?int $userId): void
    {
        if (in_array($c->status, ['completed', 'cancelled'], true)) {
            return;
        }
        // Cancelar NÃO apaga resultados concluídos; só impede novos dispatches.
        $c->update(['status' => 'cancelled']);
        $this->event($c, $userId, 'cancelled');
    }

    // ── dispatch governado (o coração do controle) ────────────────────────────
    /**
     * Despacha itens PENDING só até os slots livres (running < max_concurrent) e enquanto o
     * orçamento (com reserva atômica) permitir. Fase 1 (representantes) antes da fase 2 (réplicas).
     */
    public function dispatchAvailable(SourceSemanticCampaign $c): void
    {
        $c->refresh();
        if ($c->status !== 'running') {
            return;
        }

        $runningNow = SourceSemanticCampaignItem::where('campaign_id', $c->id)->where('status', 'running')->count();
        $slots = max(0, (int) $c->max_concurrent - $runningNow);
        if ($slots === 0) {
            return;
        }

        // fase 1 primeiro (garante 1 IA por blob antes das réplicas), depois fase 2.
        $pending = SourceSemanticCampaignItem::where('campaign_id', $c->id)
            ->where('status', 'pending')
            ->orderBy('phase')->orderByRaw("case band when 'pequeno' then 1 when 'medio' then 2 when 'grande' then 3 else 9 end")
            ->orderBy('id')
            ->limit($slots)->get();

        if ($pending->isEmpty()) {
            $this->maybeFinish($c);
            return;
        }

        foreach ($pending as $item) {
            $est = (float) $item->estimated_cost_usd;
            // reserva ATÔMICA; se estouraria o teto operacional → auto-pausa (não despacha).
            if ($est > 0 && ! $this->ledger->tryReserve($c->id, $est)) {
                $this->pause($c, null, 'campaign_budget_reached');
                return;
            }
            // marca dispatched (evita corrida de duplo-dispatch) e enfileira 1 job.
            $affected = SourceSemanticCampaignItem::whereKey($item->id)->where('status', 'pending')
                ->update(['status' => 'running', 'dispatched_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
            if ($affected === 0) {
                if ($est > 0) {
                    $this->ledger->release($c->id, $est);
                }
                continue; // já pego por outro dispatch
            }
            ProcessCampaignItemJob::dispatch($c->id, $item->id);
        }
    }

    // ── contadores + término ───────────────────────────────────────────────────
    public function bumpCounters(SourceSemanticCampaign $c, string $itemStatus, bool $reused): void
    {
        DB::transaction(function () use ($c, $itemStatus, $reused) {
            $row = SourceSemanticCampaign::whereKey($c->id)->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $row->processed++;
            if ($reused) {
                $row->reused++;
            }
            match ($itemStatus) {
                'completed' => $row->completed++,
                'partial'   => $row->partial++,
                'reused'    => $row->completed++, // reuso entrega documento completo p/ o doc
                'skipped'   => $row->skipped++,
                'cost_limit'=> $row->cost_limit_skipped++,
                'failed'    => $row->failed++,
                default     => null,
            };
            $row->save();
        });
    }

    /** Se não há mais pending nem running → conclui (ou completed_with_errors). */
    public function maybeFinish(SourceSemanticCampaign $c): void
    {
        $c->refresh();
        if ($c->status !== 'running') {
            return;
        }
        $left = SourceSemanticCampaignItem::where('campaign_id', $c->id)->whereIn('status', ['pending', 'running'])->count();
        if ($left > 0) {
            return;
        }
        $failed = SourceSemanticCampaignItem::where('campaign_id', $c->id)->whereIn('status', ['failed', 'skipped'])->count();
        $c->update(['status' => $failed > 0 ? 'completed_with_errors' : 'completed', 'completed_at' => now()]);
        $this->event($c, null, 'completed', ['failed_or_skipped' => $failed]);
    }

    /** Auto-pause por thresholds (erro alto, custo anômalo). Chamado após cada item. */
    public function maybeAutoPause(SourceSemanticCampaign $c): void
    {
        $c->refresh();
        if ($c->status !== 'running') {
            return;
        }
        $done = (int) $c->processed;
        if ($done >= 10) {
            $errRate = ((int) $c->failed) / max(1, $done);
            if ($errRate > (float) config('services.source_doc_ai.campaign_error_rate_max', 0.15)) {
                $this->pause($c, null, 'error_rate');
            }
        }
    }

    private function event(SourceSemanticCampaign $c, ?int $userId, string $event, array $meta = []): void
    {
        SourceSemanticCampaignEvent::create([
            'campaign_id' => $c->id, 'actor_user_id' => $userId, 'event' => $event,
            'meta' => $meta ?: null, 'created_at' => now(),
        ]);
    }
}
