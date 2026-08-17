<?php

namespace Tests\Feature;

use App\Models\SourceSemanticCampaign;
use App\SourceCode\Campaign\CampaignBudgetLedger;
use App\SourceCode\Campaign\CampaignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Campanha — os controles críticos (ajustes obrigatórios): reserva atômica de orçamento (o teto
 * nunca é ultrapassado nem com concorrência), auto-pause por orçamento, e máquina de estados.
 */
class SourceDocCampaignTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function campaign(array $over = []): SourceSemanticCampaign
    {
        return SourceSemanticCampaign::create(array_merge([
            'name' => 'T', 'status' => 'ready', 'budget_usd' => 1.00, 'safety_margin' => 0.00, 'max_concurrent' => 1,
        ], $over));
    }

    public function test_reserve_never_exceeds_operational_limit(): void
    {
        // budget 1.00, margem 0 → limite 1.00. Reservas de 0.20: 5 passam (=1.00), a 6ª é negada.
        $c = $this->campaign(['budget_usd' => 1.00, 'safety_margin' => 0.00]);
        $ledger = new CampaignBudgetLedger();
        $ok = 0;
        for ($i = 0; $i < 8; $i++) {
            if ($ledger->tryReserve($c->id, 0.20)) {
                $ok++;
            }
        }
        $this->assertSame(5, $ok, '5 reservas de 0,20 cabem em 1,00; as demais são negadas');
        $this->assertEqualsWithDelta(1.00, (float) $c->refresh()->reserved_cost_usd, 0.0001);
        // teto NUNCA ultrapassado (reservado + gasto <= limite)
        $this->assertLessThanOrEqual($c->operationalLimit() + 0.0001, (float) $c->reserved_cost_usd + (float) $c->actual_cost_usd);
    }

    public function test_safety_margin_reduces_operational_limit(): void
    {
        // budget 300, margem 8% → limite ~276.
        $c = $this->campaign(['budget_usd' => 300, 'safety_margin' => 0.08]);
        $this->assertEqualsWithDelta(276.0, $c->operationalLimit(), 0.01);
    }

    public function test_settle_and_release(): void
    {
        $c = $this->campaign(['budget_usd' => 1.00, 'safety_margin' => 0.00]);
        $ledger = new CampaignBudgetLedger();
        $ledger->tryReserve($c->id, 0.20);          // reserved=0.20
        $ledger->settle($c->id, 0.20, 0.13);        // reserved-0.20, actual+0.13
        $c->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $c->reserved_cost_usd, 0.0001);
        $this->assertEqualsWithDelta(0.13, (float) $c->actual_cost_usd, 0.0001);
        // release devolve reserva sem gasto (falha/cancelamento/reuso $0)
        $ledger->tryReserve($c->id, 0.20);
        $ledger->release($c->id, 0.20);
        $this->assertEqualsWithDelta(0.0, (float) $c->refresh()->reserved_cost_usd, 0.0001);
    }

    public function test_budget_auto_pause_before_dispatch(): void
    {
        // orçamento minúsculo + 1 item médio (est 0.20) → dispatch tenta reservar, estoura, PAUSA.
        $c = $this->campaign(['budget_usd' => 0.05, 'safety_margin' => 0.00, 'status' => 'running']);
        \App\Models\SourceSemanticCampaignItem::create([
            'campaign_id' => $c->id, 'source_doc_id' => 999999, 'blob_sha' => 'x', 'band' => 'medio',
            'is_representative' => true, 'phase' => 1, 'status' => 'pending', 'estimated_cost_usd' => 0.20,
        ]);
        $svc = new CampaignService(new CampaignBudgetLedger());
        $svc->dispatchAvailable($c);
        $c->refresh();
        $this->assertSame('paused', $c->status);
        $this->assertSame('campaign_budget_reached', $c->pause_reason);
        // item permanece pending (não despachado)
        $this->assertSame('pending', \App\Models\SourceSemanticCampaignItem::where('campaign_id', $c->id)->first()->status);
    }

    public function test_state_machine_transitions(): void
    {
        $svc = new CampaignService(new CampaignBudgetLedger());
        $c = $this->campaign(['status' => 'ready']);
        // item pending (est 0 = réplica/reuso) p/ não concluir na hora; o job vai p/ a fila 'database' (não roda no teste).
        \App\Models\SourceSemanticCampaignItem::create([
            'campaign_id' => $c->id, 'source_doc_id' => 888888, 'blob_sha' => 'y', 'band' => 'pequeno',
            'is_representative' => false, 'phase' => 2, 'status' => 'pending', 'estimated_cost_usd' => 0.0,
        ]);
        $svc->start($c, 1);
        $this->assertSame('running', $c->refresh()->status);
        $svc->pause($c, 1);
        $this->assertSame('paused', $c->refresh()->status);
        $svc->resume($c, 1);
        $this->assertSame('running', $c->refresh()->status);
        $svc->cancel($c, 1);
        $this->assertSame('cancelled', $c->refresh()->status);
        // cancelada não reinicia
        $svc->start($c, 1);
        $this->assertSame('cancelled', $c->refresh()->status);
    }
}
