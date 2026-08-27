<?php

namespace Tests\Feature;

use App\Models\ClientSourceRepo;
use App\Models\Customer;
use App\Models\Project;
use App\Models\SourceDoc;
use App\Models\SourceDocInventorySettings;
use App\Models\SourceDocVersion;
use App\Models\User;
use App\SourceCode\Cost\CostSettingsResolver;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\Inventory\InventorySettingsResolver;
use App\SourceCode\SourceDocInventory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase B — allowlist de extensões (source_doc_inventory_settings), INDEPENDENTE do custo.
 * Prova: resolução repo→customer→global→system_default + NULL/[]; eligible() consome a lista resolvida;
 * inventário e custo não se afetam; anti-IDOR por escopo.
 */
class SourceDocInventorySettingsTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private Customer $custB;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config([
            'cache.default' => 'array', 'multiempresa.scoping_enabled' => false,
            'services.source_doc.inventory_extensions' => ['prw', 'prx', 'prg', 'tlpp', 'tlp', 'aph'],
        ]);
        $this->custA = Customer::factory()->create();
        $this->custB = Customer::factory()->create();
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function resolver(): InventorySettingsResolver
    {
        return app(InventorySettingsResolver::class);
    }

    private function setScope(string $type, int $id, ?array $exts): void
    {
        SourceDocInventorySettings::updateOrCreate(['scope_type' => $type, 'scope_id' => $id], ['inventory_extensions' => $exts]);
    }

    // ── resolver: precedência + NULL + [] + system_default ────────────────────
    public function test_resolver_precedence_and_null_and_empty(): void
    {
        $r = $this->resolver();

        // system_default (sem nenhuma linha)
        $eff = $r->resolve($this->custA->id, null);
        $this->assertSame('system_default', $eff['origin']);
        $this->assertSame(['prw', 'prx', 'prg', 'tlpp', 'tlp', 'aph'], $eff['extensions']);

        // global override
        $this->setScope('global', 0, ['prw', 'tlpp']);
        $eff = $r->resolve($this->custA->id, null);
        $this->assertSame('global', $eff['origin']);
        $this->assertSame(['prw', 'tlpp'], $eff['extensions']);

        // customer override (precede global)
        $this->setScope('customer', $this->custA->id, ['prw']);
        $eff = $r->resolve($this->custA->id, null);
        $this->assertSame('customer', $eff['origin']);
        $this->assertSame(['prw'], $eff['extensions']);

        // repo override (precede customer)
        $this->setScope('repo', 999, ['tlpp']);
        $eff = $r->resolve($this->custA->id, 999);
        $this->assertSame('repo', $eff['origin']);
        $this->assertSame(['tlpp'], $eff['extensions']);

        // NULL no nível repo = herda do customer (não é override)
        $this->setScope('repo', 999, null);
        $eff = $r->resolve($this->custA->id, 999);
        $this->assertSame('customer', $eff['origin']);

        // [] = override EXPLÍCITO (nenhuma extensão), distinto de NULL
        $this->setScope('customer', $this->custA->id, []);
        $eff = $r->resolve($this->custA->id, null);
        $this->assertSame('customer', $eff['origin']);
        $this->assertSame([], $eff['extensions']);

        // outra empresa não afetada
        $effB = $r->resolve($this->custB->id, null);
        $this->assertSame('global', $effB['origin']); // custB herda o global (['prw','tlpp'])
    }

    public function test_remove_override_falls_back(): void
    {
        $this->setScope('customer', $this->custA->id, ['prw']);
        $this->assertSame('customer', $this->resolver()->resolve($this->custA->id, null)['origin']);
        SourceDocInventorySettings::where('scope_type', 'customer')->where('scope_id', $this->custA->id)->delete();
        $this->assertSame('system_default', $this->resolver()->resolve($this->custA->id, null)['origin']);
    }

    // ── eligible() consome a lista resolvida (funcional) ──────────────────────
    public function test_eligible_uses_resolved_allowlist(): void
    {
        $repo = ClientSourceRepo::create([
            'customer_id' => $this->custA->id, 'owner' => 'o', 'repository' => 'r', 'branch' => 'main',
            'base_path' => '', 'tipo' => 'protheus', 'active' => true,
        ]);
        // árvore: 1 .prw + 1 .txt. Pré-cria docs p/ NÃO acionar o pipeline (eligível já documentado).
        foreach (['A.PRW' => 'b1', 'B.TXT' => 'b2'] as $path => $blob) {
            $doc = SourceDoc::create(['owner' => 'o', 'repository' => 'r', 'branch' => 'main', 'path' => $path,
                'filename' => $path, 'lang' => 'advpl', 'tipo' => 'protheus', 'analysis_status' => 'completed', 'customer_id' => $this->custA->id]);
            $ver = SourceDocVersion::create(['source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . $blob, 'source_blob_sha' => $blob, 'analysis_status' => 'completed']);
            $doc->forceFill(['current_version_id' => $ver->id])->save();
        }
        $this->app->instance(GithubAppAuth::class, new class extends GithubAppAuth {
            public function __construct() {}
            public function treeBlobShas(string $o, string $r, string $ref): array { return ['A.PRW' => 'b1', 'B.TXT' => 'b2']; }
            public function getBranchHeadSha(string $o, string $r, string $b): ?string { return 'head'; }
        });
        $inv = app(SourceDocInventory::class);

        // default (prw no allowlist, txt não) → 1 elegível, 1 ignorado
        $cov = $inv->scanRepo($repo->fresh());
        $this->assertSame(1, (int) $cov->eligible_source_files);
        $this->assertSame(1, (int) $cov->ignored_files);

        // [] override no repo → 0 elegível, 2 ignorados (PROVA que eligible() honra o [])
        $this->setScope('repo', $repo->id, []);
        $cov = $inv->scanRepo($repo->fresh());
        $this->assertSame(0, (int) $cov->eligible_source_files, '[] deve zerar elegibilidade no eligible()');
        $this->assertSame(2, (int) $cov->ignored_files);

        // override ['txt'] → o .txt passa a ser elegível e o .prw ignorado (lista resolvida dirige eligible())
        $this->setScope('repo', $repo->id, ['txt']);
        $cov = $inv->scanRepo($repo->fresh());
        $this->assertSame(1, (int) $cov->eligible_source_files);
        $this->assertSame('B.TXT', SourceDoc::where('path', 'B.TXT')->value('path')); // sanity
    }

    // ── independência inventário × custo ──────────────────────────────────────
    public function test_inventory_override_does_not_affect_cost(): void
    {
        $cost = app(CostSettingsResolver::class);
        $before = $cost->forScope($this->custA->id, null);
        // custA herda o custo do nível global (linha global seedada) — SEM override de custo próprio.
        $this->assertSame('global', $before->source);
        $this->assertNull($cost->ownRow('customer', $this->custA->id), 'custA não tem override de custo próprio');

        // cria override de INVENTÁRIO para custA
        $this->setScope('customer', $this->custA->id, ['prw']);

        $after = $cost->forScope($this->custA->id, null);
        $this->assertSame('global', $after->source, 'override de inventário NÃO pode criar override de custo');
        $this->assertSame($before->automaticCostLimitUsd, $after->automaticCostLimitUsd);
        $this->assertNull($cost->ownRow('customer', $this->custA->id), 'inventário não pode criar linha de custo para custA');

        // e o inverso: nenhuma linha de inventário foi criada por override de custo
        \App\Models\SourceDocAiSettings::updateOrCreate(['scope_type' => 'customer', 'scope_id' => $this->custA->id], [
            'automatic_cost_limit_usd' => 2.0, 'safety_margin_percent' => 10, 'max_semantic_step_usd' => 0.3,
            'approval_required_above_limit' => true, 'max_approved_cost_usd' => 5.0,
        ]);
        // inventário de custA continua sendo o override próprio (['prw']), independente do custo
        $this->assertSame('customer', $this->resolver()->resolve($this->custA->id, null)['origin']);
        $this->assertSame(['prw'], $this->resolver()->resolve($this->custA->id, null)['extensions']);
    }

    // ── anti-IDOR ─────────────────────────────────────────────────────────────
    public function test_anti_idor(): void
    {
        $coordA = $this->coordFor($this->custA);
        $payload = fn ($cid) => ['scope_type' => 'customer', 'scope_id' => $cid, 'extensions' => ['prw']];

        // PUT no override de B → 403; do próprio A → 200
        $this->actingAs($coordA, 'sanctum')->putJson('/api/v1/source-docs/inventory-settings', $payload($this->custB->id))->assertForbidden();
        $this->actingAs($coordA, 'sanctum')->putJson('/api/v1/source-docs/inventory-settings', $payload($this->custA->id))->assertOk();

        // resolve de B → 403; de A → 200
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/inventory-settings/resolve?customer_id={$this->custB->id}")->assertForbidden();
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/inventory-settings/resolve?customer_id={$this->custA->id}")->assertOk();
    }

    // ── validação: rejeita extensão inválida, não aceita CSV, [] permitido ────
    public function test_write_validation(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        // extensão inválida → 422
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/source-docs/inventory-settings', ['scope_type' => 'global', 'scope_id' => 0, 'extensions' => ['pr w!']])->assertStatus(422);
        // CSV (string) → 422 (não é array)
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/source-docs/inventory-settings', ['scope_type' => 'global', 'scope_id' => 0, 'extensions' => 'prw,tlpp'])->assertStatus(422);
        // [] permitido; normaliza (lowercase/sem ponto/dedup)
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/source-docs/inventory-settings', ['scope_type' => 'global', 'scope_id' => 0, 'extensions' => []])->assertOk();
        $r = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/source-docs/inventory-settings', ['scope_type' => 'global', 'scope_id' => 0, 'extensions' => ['.PRW', 'prw', 'TLPP']]);
        $r->assertOk();
        $this->assertSame(['prw', 'tlpp'], $r->json('data.extensions'));
    }

    private function coordFor(Customer $c): User
    {
        $u = User::factory()->create(['type' => 'coordenador', 'extra_permissions' => ['source_docs.view', 'source_docs.inventory']]);
        $proj = Project::factory()->create(['customer_id' => $c->id]);
        DB::table('project_coordinators')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }
}
