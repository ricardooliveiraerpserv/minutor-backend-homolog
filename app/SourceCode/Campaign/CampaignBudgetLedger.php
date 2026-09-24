<?php

namespace App\SourceCode\Campaign;

use App\Models\SourceSemanticCampaign;
use Illuminate\Support\Facades\DB;

/**
 * LEDGER de orçamento da campanha com RESERVA ATÔMICA (ajuste obrigatório #1).
 *
 * O teto global só é garantido se o dispatch considerar o custo RESERVADO (jobs em voo), não só o
 * realizado. Sob concorrência, vários dispatches poderiam ver o mesmo actual_cost e passar todos.
 * Aqui a decisão é serializada por transação + lockForUpdate na linha da campanha:
 *
 *   dispatch ⇔ actual_cost + reserved_cost + estimated_next <= operational_limit  → reserva estimated_next
 *   finish   ⇔ reserved -= estimated ; actual += real
 *   fail/cancel ⇔ reserved -= estimated (libera a reserva)
 */
class CampaignBudgetLedger
{
    /**
     * Tenta reservar o custo estimado da próxima análise. Atômico. Retorna true se reservou.
     */
    public function tryReserve(int $campaignId, float $estimatedNext): bool
    {
        return DB::transaction(function () use ($campaignId, $estimatedNext) {
            /** @var SourceSemanticCampaign $c */
            $c = SourceSemanticCampaign::query()->whereKey($campaignId)->lockForUpdate()->first();
            if (! $c) {
                return false;
            }
            $limit = $c->operationalLimit();
            $projected = (float) $c->actual_cost_usd + (float) $c->reserved_cost_usd + $estimatedNext;
            if ($projected > $limit) {
                return false; // estouraria o teto operacional → NÃO despacha
            }
            $c->reserved_cost_usd = (float) $c->reserved_cost_usd + $estimatedNext;
            $c->save();
            return true;
        });
    }

    /** Fecha um item: converte reserva em custo real (atômico). */
    public function settle(int $campaignId, float $reservedEstimate, float $actualCost): void
    {
        DB::transaction(function () use ($campaignId, $reservedEstimate, $actualCost) {
            $c = SourceSemanticCampaign::query()->whereKey($campaignId)->lockForUpdate()->first();
            if (! $c) {
                return;
            }
            $c->reserved_cost_usd = max(0, (float) $c->reserved_cost_usd - $reservedEstimate);
            $c->actual_cost_usd = (float) $c->actual_cost_usd + max(0, $actualCost);
            $c->save();
        });
    }

    /** Libera a reserva sem gasto (falha/cancelamento/reuso a US$ 0). */
    public function release(int $campaignId, float $reservedEstimate): void
    {
        DB::transaction(function () use ($campaignId, $reservedEstimate) {
            $c = SourceSemanticCampaign::query()->whereKey($campaignId)->lockForUpdate()->first();
            if (! $c) {
                return;
            }
            $c->reserved_cost_usd = max(0, (float) $c->reserved_cost_usd - $reservedEstimate);
            $c->save();
        });
    }
}
