<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SourceDoc;
use App\Models\SourceDocAiSettings;
use App\Models\SourceDocCostApproval;
use App\Models\SourceDocSourceRequest;
use App\Models\SourceDocVersion;
use App\Models\User;
use App\SourceCode\GithubAppAuth;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase A (GAP-FE-002) — anti-IDOR nos endpoints que passaram a operar em contexto client-facing:
 * cost-settings (Governança), cost-approvals (Aprovações), source-requests (Publicações).
 * A autoridade do tenant vem da ENTIDADE REAL (customer/override/fonte), revalidada no servidor
 * via SourceDocCustomerScope — nunca do parâmetro do browser. Um coordenador escopado ao cliente A
 * não pode ler/decidir/editar dados do cliente B (403/404); admin (global) pode tudo.
 */
class SourceDocFaseAScopeTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private Customer $custB;
    private SourceDoc $docA;
    private SourceDoc $docB;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false]);
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));

        $this->custA = Customer::factory()->create();
        $this->custB = Customer::factory()->create();
        $this->docA = $this->makeDoc($this->custA->id);
        $this->docB = $this->makeDoc($this->custB->id);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function fakeAuth(string $blob): GithubAppAuth
    {
        return new class($blob) extends GithubAppAuth {
            public function __construct(private string $blob) { parent::__construct(); }
            public function getFileWithSha(string $o, string $r, string $ref, string $p): ?array { return ['content' => 'code', 'blob_sha' => $this->blob]; }
            public function treeBlobShas(string $o, string $r, string $ref): array { return []; }
        };
    }

    private function makeDoc(int $customerId): SourceDoc
    {
        $doc = SourceDoc::create([
            'owner' => 'erpserv-clientes', 'repository' => 'rep' . $customerId, 'branch' => 'main',
            'path' => 'x/' . uniqid() . '.prw', 'filename' => 'CCSPCP.PRW', 'lang' => 'advpl',
            'tipo' => 'protheus', 'analysis_status' => 'completed', 'customer_id' => $customerId,
        ]);
        $ver = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(), 'source_blob_sha' => 'blobA',
            'analysis_status' => 'completed',
        ]);
        $doc->forceFill(['current_version_id' => $ver->id])->save();
        return $doc->refresh();
    }

    /** Coordenador vinculado (pivot) ao cliente + permissões das telas Fase A. Escopo = só esse cliente. */
    private function coordFor(Customer $c): User
    {
        $u = User::factory()->create(['type' => 'coordenador', 'extra_permissions' => [
            'source_docs.view', 'source_docs.inventory',
            'source_docs.cost_settings.view', 'source_docs.cost_settings.manage',
            'source_docs.cost_approval.view', 'source_docs.cost_approval.decide',
        ]]);
        $proj = Project::factory()->create(['customer_id' => $c->id]);
        DB::table('project_coordinators')->insert([
            'project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $u;
    }

    private function admin(): User
    {
        return User::factory()->create(['type' => 'admin']);
    }

    private function approvalFor(SourceDoc $doc): SourceDocCostApproval
    {
        return SourceDocCostApproval::create([
            'source_doc_id' => $doc->id, 'status' => 'pending', 'next_step' => 'top_up',
            'actual_cost_usd' => 0.5, 'authorized_limit_usd' => 1.0, 'estimated_next_usd' => 0.3,
        ]);
    }

    private function customerOverride(Customer $c): SourceDocAiSettings
    {
        return SourceDocAiSettings::create([
            'scope_type' => 'customer', 'scope_id' => $c->id,
            'automatic_cost_limit_usd' => 1.0, 'safety_margin_percent' => 10.0, 'max_semantic_step_usd' => 0.3,
            'approval_required_above_limit' => true, 'max_approved_cost_usd' => 3.0, 'approval_mandatory_above_usd' => null,
        ]);
    }

    private function sourceRequestFor(Customer $c): SourceDocSourceRequest
    {
        return SourceDocSourceRequest::create([
            'customer_id' => $c->id, 'repository' => 'rep' . $c->id, 'scope_type' => 'repository',
            'priority' => 'media', 'status' => 'open',
        ]);
    }

    // ── cost-approvals (Aprovações) ──────────────────────────────────────────
    public function test_cost_approvals_index_scoped_to_own_customer(): void
    {
        $apA = $this->approvalFor($this->docA);
        $apB = $this->approvalFor($this->docB);
        $coordA = $this->coordFor($this->custA);

        $ids = collect($this->actingAs($coordA, 'sanctum')->getJson('/api/v1/source-docs/cost-approvals?status=all')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($apA->id, $ids);
        $this->assertNotContains($apB->id, $ids, 'coordenador de A NÃO vê aprovação do cliente B');

        // admin (global) vê ambos
        $idsAdmin = collect($this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs/cost-approvals?status=all')->json('data'))->pluck('id')->all();
        $this->assertContains($apB->id, $idsAdmin);
    }

    public function test_cost_approvals_show_and_decide_idor(): void
    {
        $apB = $this->approvalFor($this->docB);
        $coordA = $this->coordFor($this->custA);

        // show/decide da aprovação de B → 404 (sem vazar existência)
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/cost-approvals/{$apB->id}")->assertNotFound();
        $this->actingAs($coordA, 'sanctum')->postJson("/api/v1/source-docs/cost-approvals/{$apB->id}/reject")->assertNotFound();

        // a mesma ação na própria aprovação (A) funciona
        $apA = $this->approvalFor($this->docA);
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/cost-approvals/{$apA->id}")->assertOk();
    }

    // ── cost-settings (Governança) ───────────────────────────────────────────
    public function test_cost_settings_write_and_resolve_idor(): void
    {
        $coordA = $this->coordFor($this->custA);
        $payload = fn ($cid) => [
            'scope_type' => 'customer', 'scope_id' => $cid,
            'automatic_cost_limit_usd' => 1.0, 'safety_margin_percent' => 10, 'max_semantic_step_usd' => 0.3,
            'approval_required_above_limit' => true, 'max_approved_cost_usd' => 3.0, 'approval_mandatory_above_usd' => null,
        ];

        // update override do cliente B → 403
        $this->actingAs($coordA, 'sanctum')->putJson('/api/v1/source-docs/cost-settings', $payload($this->custB->id))->assertForbidden();
        // update do próprio cliente A → 200
        $this->actingAs($coordA, 'sanctum')->putJson('/api/v1/source-docs/cost-settings', $payload($this->custA->id))->assertOk();

        // resolve do cliente B → 403; do A → 200
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/cost-settings/resolve?customer_id={$this->custB->id}")->assertForbidden();
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/cost-settings/resolve?customer_id={$this->custA->id}")->assertOk();

        // destroy override do cliente B → 403
        $this->customerOverride($this->custB);
        $this->actingAs($coordA, 'sanctum')->deleteJson("/api/v1/source-docs/cost-settings?scope_type=customer&scope_id={$this->custB->id}")->assertForbidden();
    }

    // ── source-requests (Publicações) ────────────────────────────────────────
    public function test_source_request_update_idor(): void
    {
        $reqB = $this->sourceRequestFor($this->custB);
        $reqA = $this->sourceRequestFor($this->custA);
        $coordA = $this->coordFor($this->custA);

        // PATCH da solicitação de B → 404 (autoridade pela entidade real)
        $this->actingAs($coordA, 'sanctum')->patchJson("/api/v1/source-docs/source-requests/{$reqB->id}", ['status' => 'provisioned'])->assertNotFound();
        // PATCH da própria (A) → 200
        $this->actingAs($coordA, 'sanctum')->patchJson("/api/v1/source-docs/source-requests/{$reqA->id}", ['status' => 'provisioned'])->assertOk();

        // listRequests não vaza a solicitação de B para o coordenador de A
        $ids = collect($this->actingAs($coordA, 'sanctum')->getJson('/api/v1/source-docs/source-requests?status=all')->json('data'))->pluck('id')->all();
        $this->assertContains($reqA->id, $ids);
        $this->assertNotContains($reqB->id, $ids);
    }
}
