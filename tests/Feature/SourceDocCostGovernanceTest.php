<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SourceDoc;
use App\Models\SourceDocAiSettings;
use App\Models\SourceDocCostApproval;
use App\Models\SourceDocCostLedger;
use App\Models\SourceDocVersion;
use App\Models\User;
use App\SourceCode\Cost\CostSettingsResolver;
use App\SourceCode\Cost\SourceCostGovernor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Central de Fontes — Frente A (Governança de Custo). Prova o enforcement POR FONTE na orquestração
 * (motor congelado intocado): limite operacional = auto×(1−margem); actual+reserved+next ≤ operacional;
 * estouro com aprovação ligada → fila (sem IA); approve-step (one-shot) × approve-limit (teto durável);
 * settle/release; cascata de config com origem; validações da tela de config.
 */
class SourceDocCostGovernanceTest extends TestCase
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
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false]);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) {
                return trim(substr($line, strlen($key) + 1));
            }
        }
        return '';
    }

    private function governor(): SourceCostGovernor
    {
        return app(SourceCostGovernor::class);
    }

    private function makeDoc(?int $customerId = null, ?array $usage = null): SourceDoc
    {
        $doc = SourceDoc::create([
            'owner' => 'erpserv-clientes', 'repository' => 'rep' . uniqid(), 'branch' => 'main',
            'path' => 'x/' . uniqid() . '.prw', 'filename' => 'F.PRW', 'lang' => 'advpl',
            'tipo' => 'protheus', 'analysis_status' => 'partial', 'customer_id' => $customerId,
        ]);
        $ver = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(), 'source_blob_sha' => 'b' . uniqid(),
            'analysis_status' => 'partial',
            'deterministic_json' => ['functions' => [['name' => 'F1']], 'tables' => []],
            'semantic_json' => $usage ? ['usage' => $usage] : ['status' => 'partial'],
        ]);
        $doc->forceFill(['current_version_id' => $ver->id])->save();
        return $doc->refresh();
    }

    private function seedLedger(SourceDoc $doc, float $actual, float $authorized, float $reserved = 0): SourceDocCostLedger
    {
        return SourceDocCostLedger::create([
            'source_doc_id' => $doc->id, 'actual_cost_usd' => $actual,
            'reserved_cost_usd' => $reserved, 'authorized_limit_usd' => $authorized,
        ]);
    }

    // ── config / resolver ──────────────────────────────────────────────────
    public function test_operational_limit_is_auto_minus_margin(): void
    {
        $r = app(CostSettingsResolver::class)->global();
        $this->assertSame(1.0, round($r->automaticCostLimitUsd, 2));
        $this->assertSame(10.0, round($r->safetyMarginPercent, 2));
        $this->assertSame(0.9, round($r->operationalLimit(), 4)); // 1,00 × (1 − 10%)
        $this->assertSame('global', $r->source);
    }

    public function test_customer_override_wins_with_origin_label(): void
    {
        $cust = Customer::factory()->create(['name' => 'PROMAX BARDAHL']);
        SourceDocAiSettings::create([
            'scope_type' => 'customer', 'scope_id' => $cust->id,
            'automatic_cost_limit_usd' => 2.0, 'safety_margin_percent' => 0,
            'max_semantic_step_usd' => 0.30, 'approval_required_above_limit' => true, 'max_approved_cost_usd' => 3.0,
        ]);
        $doc = $this->makeDoc($cust->id);
        $r = app(CostSettingsResolver::class)->for($doc);
        $this->assertSame('customer', $r->source);
        $this->assertStringContainsString('PROMAX BARDAHL', $r->sourceLabel);
        $this->assertSame(2.0, round($r->operationalLimit(), 4)); // margem 0 → operacional = auto
    }

    // ── governor: reserva/gate ───────────────────────────────────────────────
    public function test_step_within_limit_allows_and_reserves(): void
    {
        $doc = $this->makeDoc();
        $d = $this->governor()->authorizeStep($doc, 'reprocess', 0.30);
        $this->assertTrue($d->allowed());
        $l = SourceDocCostLedger::where('source_doc_id', $doc->id)->first();
        $this->assertSame(0.30, round((float) $l->reserved_cost_usd, 4));
        $this->assertSame(0.0, round((float) $l->actual_cost_usd, 4));
        $this->assertSame(0.90, round((float) $l->authorized_limit_usd, 4));
    }

    public function test_settle_converts_reserve_to_actual(): void
    {
        $doc = $this->makeDoc();
        $this->governor()->authorizeStep($doc, 'reprocess', 0.30);
        $this->governor()->settle($doc, 0.30, 0.27);
        $l = SourceDocCostLedger::where('source_doc_id', $doc->id)->first();
        $this->assertSame(0.0, round((float) $l->reserved_cost_usd, 4));
        $this->assertSame(0.27, round((float) $l->actual_cost_usd, 4));
    }

    public function test_release_frees_reserve(): void
    {
        $doc = $this->makeDoc();
        $this->governor()->authorizeStep($doc, 'reprocess', 0.30);
        $this->governor()->release($doc, 0.30);
        $l = SourceDocCostLedger::where('source_doc_id', $doc->id)->first();
        $this->assertSame(0.0, round((float) $l->reserved_cost_usd, 4));
    }

    public function test_cumulative_over_limit_creates_pending_approval_and_no_reservation(): void
    {
        $doc = $this->makeDoc();
        $this->seedLedger($doc, actual: 0.80, authorized: 0.90); // já gastou 0,80 de 0,90
        $d = $this->governor()->authorizeStep($doc, 'top_up', 0.30); // 0,80+0,30 = 1,10 > 0,90
        $this->assertTrue($d->needsApproval());
        $this->assertNotNull($d->approval);
        $this->assertSame('pending', $d->approval->status);
        // não reservou nada
        $l = SourceDocCostLedger::where('source_doc_id', $doc->id)->first();
        $this->assertSame(0.0, round((float) $l->reserved_cost_usd, 4));
        // 2ª chamada não cria 2ª aprovação (índice parcial único / ensureApproval)
        $d2 = $this->governor()->authorizeStep($doc, 'top_up', 0.30);
        $this->assertSame($d->approval->id, $d2->approval->id);
        $this->assertSame(1, SourceDocCostApproval::where('source_doc_id', $doc->id)->where('status', 'pending')->count());
    }

    public function test_approval_off_denies_partial(): void
    {
        SourceDocAiSettings::where('scope_type', 'global')->update(['approval_required_above_limit' => false]);
        $doc = $this->makeDoc();
        $this->seedLedger($doc, actual: 0.80, authorized: 0.90);
        $d = $this->governor()->authorizeStep($doc, 'top_up', 0.30);
        $this->assertSame('deny_partial', $d->outcome);
        $this->assertSame(0, SourceDocCostApproval::where('source_doc_id', $doc->id)->count());
    }

    public function test_approve_limit_raises_cap_then_next_step_allows(): void
    {
        $doc = $this->makeDoc();
        $this->seedLedger($doc, actual: 0.80, authorized: 0.90);
        $d = $this->governor()->authorizeStep($doc, 'top_up', 0.30);
        $this->assertTrue($d->needsApproval());
        $applied = $this->governor()->approveLimit($d->approval, 1.50, userId: 1);
        $this->assertSame(1.50, round($applied, 4));
        $d2 = $this->governor()->authorizeStep($doc, 'top_up', 0.30); // 0,80+0,30 = 1,10 ≤ 1,50
        $this->assertTrue($d2->allowed());
    }

    public function test_approve_limit_capped_at_max_approved(): void
    {
        $doc = $this->makeDoc();
        $this->seedLedger($doc, actual: 2.90, authorized: 0.90);
        $d = $this->governor()->authorizeStep($doc, 'top_up', 0.30);
        $applied = $this->governor()->approveLimit($d->approval, 5.00, userId: 1); // pede 5 → clampa em 3
        $this->assertSame(3.0, round($applied, 4));
    }

    public function test_approve_step_is_one_shot(): void
    {
        $doc = $this->makeDoc();
        $this->seedLedger($doc, actual: 0.80, authorized: 0.90);
        $d = $this->governor()->authorizeStep($doc, 'top_up', 0.30);
        $this->governor()->approveStep($d->approval, userId: 1); // teto sobe só p/ 1,10
        $l = SourceDocCostLedger::where('source_doc_id', $doc->id)->first();
        $this->assertSame(1.10, round((float) $l->authorized_limit_usd, 4));
        // roda o passo e liquida com custo real → actual sobe p/ ~1,07
        $d2 = $this->governor()->authorizeStep($doc, 'top_up', 0.30);
        $this->assertTrue($d2->allowed());
        $this->governor()->settle($doc, 0.30, 0.27);
        // próximo passo volta a exigir aprovação (one-shot consumido)
        $d3 = $this->governor()->authorizeStep($doc, 'top_up', 0.30); // 1,07+0,30 = 1,37 > 1,10
        $this->assertTrue($d3->needsApproval());
    }

    // ── HTTP: config + fila ──────────────────────────────────────────────────
    public function test_settings_update_rejects_step_greater_than_auto(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/source-docs/cost-settings', [
            'automatic_cost_limit_usd' => 1.0, 'safety_margin_percent' => 10,
            'max_semantic_step_usd' => 2.0, // > auto → inválido
            'approval_required_above_limit' => true, 'max_approved_cost_usd' => 3.0,
        ])->assertStatus(422);
    }

    public function test_settings_update_persists_and_shows_operational_limit(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $resp = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/source-docs/cost-settings', [
            'automatic_cost_limit_usd' => 1.20, 'safety_margin_percent' => 10,
            'max_semantic_step_usd' => 0.30, 'approval_required_above_limit' => true, 'max_approved_cost_usd' => 3.0,
        ])->assertOk();
        $this->assertSame(1.08, round((float) $resp->json('data.global.operational_limit_usd'), 4)); // 1,20 × 0,9
        $this->assertSame('Configuração global', $resp->json('data.global.source_label'));
    }

    public function test_approvals_list_and_approve_limit_endpoint(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $doc = $this->makeDoc();
        $this->seedLedger($doc, actual: 0.80, authorized: 0.90);
        $d = $this->governor()->authorizeStep($doc, 'top_up', 0.30);
        $this->assertTrue($d->needsApproval());

        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/cost-approvals?status=pending')->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->all();
        $this->assertContains($d->approval->id, $ids);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/source-docs/cost-approvals/{$d->approval->id}/approve-limit", ['new_limit_usd' => 1.50])
            ->assertOk()->assertJsonPath('data.applied_limit_usd', 1.5);

        $this->assertSame('approved_limit', SourceDocCostApproval::find($d->approval->id)->status);
        // decidir de novo → 409
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/source-docs/cost-approvals/{$d->approval->id}/reject")
            ->assertStatus(409);
    }

    public function test_repo_override_wins_over_customer_with_origin(): void
    {
        $cust = Customer::factory()->create(['name' => 'KONECTA']);
        $doc = $this->makeDoc($cust->id);
        $doc->forceFill(['source_repo_id' => 4242])->save();
        // há override de CUSTOMER e de REPO — o de repo (mais específico) vence.
        SourceDocAiSettings::create(['scope_type' => 'customer', 'scope_id' => $cust->id, 'automatic_cost_limit_usd' => 2.0, 'safety_margin_percent' => 10, 'max_semantic_step_usd' => 0.30, 'approval_required_above_limit' => true, 'max_approved_cost_usd' => 3.0]);
        SourceDocAiSettings::create(['scope_type' => 'repo', 'scope_id' => 4242, 'automatic_cost_limit_usd' => 1.5, 'safety_margin_percent' => 0, 'max_semantic_step_usd' => 0.30, 'approval_required_above_limit' => true, 'max_approved_cost_usd' => 3.0]);
        $r = app(CostSettingsResolver::class)->for($doc->refresh());
        $this->assertSame('repo', $r->source);
        $this->assertSame(1.5, round($r->operationalLimit(), 4));
        $this->assertStringContainsString('Repositório', $r->sourceLabel);
    }

    public function test_external_client_cannot_access_cost_routes(): void
    {
        $cust = Customer::factory()->create();
        $cli = User::factory()->create(['type' => 'cliente', 'customer_id' => $cust->id]);
        $this->actingAs($cli, 'sanctum')->getJson('/api/v1/source-docs/cost-settings')->assertForbidden();
        $this->actingAs($cli, 'sanctum')->getJson('/api/v1/source-docs/cost-approvals')->assertForbidden();
        $this->actingAs($cli, 'sanctum')->putJson('/api/v1/source-docs/cost-settings', [
            'automatic_cost_limit_usd' => 1.0, 'safety_margin_percent' => 10, 'max_semantic_step_usd' => 0.30,
            'approval_required_above_limit' => true, 'max_approved_cost_usd' => 3.0,
        ])->assertForbidden();
    }
}
