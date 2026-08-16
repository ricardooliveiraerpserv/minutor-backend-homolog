<?php

namespace Tests\Feature;

use App\Jobs\InventorySourceRepoJob;
use App\Models\ClientSourceRepo;
use App\Models\Customer;
use App\Models\SourceDoc;
use App\Models\SourceRepoCoverage;
use App\Models\User;
use App\SourceCode\Analyzer\SourceDocAiProvider;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\SourceDocInventory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Central de Fontes — C3.5. Prova: inventário cataloga NOVOS deterministicamente (IA ZERO),
 * classifica novo/coberto/desatualizado, é idempotente/retomável, atualiza cobertura, e o disparo
 * é gateado. Fonte alterado NÃO é reprocessado (só contado).
 */
class SourceDocInventoryTest extends TestCase
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
        config(['cache.default' => 'array']);
        // IA que EXPLODE se chamada → prova que o inventário nunca aciona semântica.
        $this->app->instance(SourceDocAiProvider::class, new class implements SourceDocAiProvider {
            public function isConfigured(): bool { return true; }
            public function name(): string { return 'exploding'; }
            public function model(): string { return 'none'; }
            public function complete(string $s, string $u, array $o = []): array
            { throw new \RuntimeException('IA NÃO deve ser chamada no inventário!'); }
        });
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function admin(): User       { return User::factory()->create(['type' => 'admin']); }
    private function coordenador(): User { return User::factory()->create(['type' => 'coordenador']); }
    private function consultor(): User   { return User::factory()->create(['type' => 'consultor']); }

    private function repo(string $base = ''): ClientSourceRepo
    {
        return ClientSourceRepo::create([
            'customer_id' => Customer::factory()->create()->id,
            'owner' => 'erpserv-clientes', 'repository' => 'concreserv', 'branch' => 'main',
            'base_path' => $base, 'tipo' => 'protheus', 'active' => true,
        ]);
    }

    /** GitHub fake: árvore path→blob controlável + conteúdo AdvPL. */
    private function fakeAuth(array $tree, string $head = 'headA'): GithubAppAuth
    {
        return new class($tree, $head) extends GithubAppAuth {
            public function __construct(private array $tree, private string $head) { parent::__construct(); }
            public function treeBlobShas(string $o, string $r, string $ref): array { return $this->tree; }
            public function getBranchHeadSha(string $o, string $r, string $b): ?string { return $this->head; }
            public function getFileContent(string $o, string $r, string $ref, string $p): ?string
            { return "#include 'protheus.ch'\nUser Function T_" . md5($p) . "()\nReturn .T.\n"; }
        };
    }

    // ── inventário determinístico ────────────────────────────────────────────

    public function test_catalogs_new_deterministic_without_ai_and_filters(): void
    {
        $repo = $this->repo();
        // 2 elegíveis (.prw/.tlpp) + 1 não-elegível (.md)
        $tree = ['src/A.PRW' => 'b1', 'src/B.tlpp' => 'b2', 'README.md' => 'b3'];
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth($tree));

        $cov = app(SourceDocInventory::class)->scanRepo($repo);

        $this->assertSame('completed', $cov->scan_status);
        $this->assertSame(3, $cov->github_files);
        $this->assertSame(2, $cov->eligible_source_files);
        $this->assertSame(2, $cov->new_files);
        $this->assertSame(1, $cov->ignored_files);
        $this->assertSame(2, $cov->cataloged);
        $this->assertSame(2, $cov->deterministic);
        $this->assertSame(0, $cov->semantic);        // IA zero
        $this->assertSame(2, $cov->indexed);          // C2 populado

        // fontes criados, determinístico, SEM semântica; blob = o da árvore
        $expected = ['src/A.PRW' => 'b1', 'src/B.tlpp' => 'b2'];
        $docs = SourceDoc::where('repository', 'concreserv')->get();
        $this->assertCount(2, $docs);
        foreach ($docs as $d) {
            $v = $d->currentVersion;
            $this->assertNotNull($v->deterministic_json);
            $this->assertNull($v->semantic_json);      // prova: sem IA
            $this->assertSame($expected[$d->path], $v->source_blob_sha);
        }
    }

    public function test_idempotent_second_run_no_duplicates(): void
    {
        $repo = $this->repo();
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth(['src/A.PRW' => 'b1', 'src/B.PRW' => 'b2']));
        app(SourceDocInventory::class)->scanRepo($repo);
        $cov2 = app(SourceDocInventory::class)->scanRepo($repo);

        $this->assertSame(0, $cov2->new_files);
        $this->assertSame(2, $cov2->unchanged_files);   // cobertos
        $this->assertSame(2, SourceDoc::where('repository', 'concreserv')->count()); // sem dup
    }

    public function test_changed_blob_counts_as_outdated_not_reprocessed(): void
    {
        $repo = $this->repo();
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth(['src/A.PRW' => 'b1']));
        app(SourceDocInventory::class)->scanRepo($repo);
        $verBefore = SourceDoc::where('path', 'src/A.PRW')->first()->current_version_id;

        // blob muda → documentação desatualizada
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth(['src/A.PRW' => 'bNOVO']));
        $cov = app(SourceDocInventory::class)->scanRepo($repo);

        $this->assertSame(0, $cov->new_files);
        $this->assertSame(1, $cov->changed_files);       // desatualizado
        $verAfter = SourceDoc::where('path', 'src/A.PRW')->first()->current_version_id;
        $this->assertSame($verBefore, $verAfter, 'NÃO deve reprocessar/criar versão nova');
    }

    public function test_batch_limit_partial_then_resume_completes(): void
    {
        $repo = $this->repo();
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth(['src/A.PRW' => 'b1', 'src/B.PRW' => 'b2', 'src/C.PRW' => 'b3']));
        $cov1 = app(SourceDocInventory::class)->scanRepo($repo, 2); // lote de 2
        $this->assertSame('partial', $cov1->scan_status);
        $this->assertSame(2, $cov1->new_files);
        $this->assertNotNull($cov1->last_scan_cursor);

        $cov2 = app(SourceDocInventory::class)->scanRepo($repo, 2); // retoma
        $this->assertSame('completed', $cov2->scan_status);
        $this->assertNull($cov2->last_scan_cursor);
        $this->assertSame(3, SourceDoc::where('repository', 'concreserv')->count());
    }

    public function test_base_path_filter(): void
    {
        $repo = $this->repo('RPO'); // só dentro de RPO/
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth(['RPO/A.PRW' => 'b1', 'OUTRO/B.PRW' => 'b2']));
        $cov = app(SourceDocInventory::class)->scanRepo($repo);
        $this->assertSame(1, $cov->eligible_source_files); // só RPO/A.PRW
        $this->assertSame(1, $cov->new_files);
    }

    // ── permissões / endpoints ───────────────────────────────────────────────

    public function test_coverage_and_inventory_permissions(): void
    {
        Queue::fake();
        $this->repo();
        // coverage = source_docs.view (admin/coord ok)
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs/coverage')->assertOk();
        $this->actingAs($this->coordenador(), 'sanctum')->getJson('/api/v1/source-docs/coverage')->assertOk();
        // inventory = source_docs.inventory ESTRITO (admin ok; coord/consultor 403)
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/source-docs/inventory', [])->assertStatus(202);
        $this->actingAs($this->coordenador(), 'sanctum')->postJson('/api/v1/source-docs/inventory', [])->assertForbidden();
        $this->actingAs($this->consultor(), 'sanctum')->postJson('/api/v1/source-docs/inventory', [])->assertForbidden();
        Queue::assertPushed(InventorySourceRepoJob::class);
    }

    public function test_inventory_job_targets_source_doc_queue(): void
    {
        Queue::fake();
        $this->repo();
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/source-docs/inventory', [])->assertStatus(202);
        Queue::assertPushed(InventorySourceRepoJob::class, fn ($j) => $j->connection === 'database' && $j->queue === 'source-doc');
    }
}
