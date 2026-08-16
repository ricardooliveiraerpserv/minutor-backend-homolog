<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SourceDoc;
use App\Models\SourceDocActionLog;
use App\Models\SourceDocEntity;
use App\Models\SourceDocVersion;
use App\Models\User;
use App\SourceCode\GithubAppAuth;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C4a — Prova de FOGO nos 16 endpoints: escopo por cliente aplicado ponta-a-ponta.
 * Matriz Perfil × Cliente × Endpoint × Resultado + IDOR (acesso por ID de outro cliente → 404)
 * + busca/suggest sem vazamento + indicadores escopados + auditoria de negativa +
 * cliente externo com view_all indevido continua vendo só o próprio (ponta-a-ponta).
 */
class SourceDocScopeEndpointsTest extends TestCase
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
        $this->docA = $this->makeDoc($this->custA->id, 'SC2010', 'FieldA');
        $this->docB = $this->makeDoc($this->custB->id, 'SC5010', 'FieldB');
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
            public function getBranchHeadSha(string $o, string $r, string $b): ?string { return 'headsha'; }
            public function getFileContent(string $o, string $r, string $ref, string $p): ?string { return 'old'; }
        };
    }

    private function makeDoc(int $customerId, string $tableName, string $fieldName): SourceDoc
    {
        $doc = SourceDoc::create([
            'owner' => 'erpserv-clientes', 'repository' => 'rep' . $customerId, 'branch' => 'main',
            'path' => 'x/' . uniqid() . '.prw', 'filename' => 'CCSPCP.PRW', 'lang' => 'advpl',
            'tipo' => 'protheus', 'analysis_status' => 'completed', 'customer_id' => $customerId,
        ]);
        $ver = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(), 'source_blob_sha' => 'blobA',
            'analysis_status' => 'completed',
            'deterministic_json' => ['functions' => [['name' => 'F1']], 'tables' => []],
            'documentation_json' => ['identity' => ['filename' => 'CCSPCP.PRW'], 'semantic' => ['objetivo' => 'x'], 'deterministic' => ['functions' => []]],
        ]);
        $doc->forceFill(['current_version_id' => $ver->id, 'documentation_json' => $ver->documentation_json])->save();
        // read-model C2 (busca/suggest): 1 tabela + 1 campo por doc, com customer_id.
        foreach ([['table', $tableName], ['field', $fieldName]] as [$type, $name]) {
            SourceDocEntity::create([
                'source_doc_id' => $doc->id, 'source_doc_version_id' => $ver->id, 'customer_id' => $customerId,
                'owner' => $doc->owner, 'repository' => $doc->repository, 'branch' => 'main',
                'entity_type' => $type, 'name' => $name, 'access' => ['READ'], 'risk_flags' => [],
            ]);
        }
        return $doc->refresh();
    }

    /** Coordenador vinculado (via pivot) ao cliente informado — tem source_docs.view. */
    private function coordFor(Customer $c): User
    {
        $u = User::factory()->create(['type' => 'coordenador']);
        $proj = Project::factory()->create(['customer_id' => $c->id]);
        DB::table('project_coordinators')->insert([
            'project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $u;
    }

    // ── índice/catálogo escopado ────────────────────────────────────────────
    public function test_index_scoped_to_own_customer(): void
    {
        $coordA = $this->coordFor($this->custA);
        $resp = $this->actingAs($coordA, 'sanctum')->getJson('/api/v1/source-docs')->assertOk();
        $ids = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($this->docA->id, $ids);
        $this->assertNotContains($this->docB->id, $ids);
        // indicadores escopados: total conta só o cliente A
        $this->assertSame(1, $resp->json('indicators.total'));
    }

    public function test_admin_sees_all(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $ids = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs')->json('data'))->pluck('id')->all();
        $this->assertContains($this->docA->id, $ids);
        $this->assertContains($this->docB->id, $ids);
    }

    // ── IDOR: acesso por ID de outro cliente → 404 em TODOS os endpoints {id} ──
    public function test_idor_all_id_endpoints_return_404_out_of_scope(): void
    {
        $coordA = $this->coordFor($this->custA);
        $b = $this->docB->id;

        // Próprio (A) responde
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/{$this->docA->id}")->assertOk();

        // De outro cliente (B) → 404 em cada endpoint por ID
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/{$b}")->assertNotFound();
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/{$b}/documentation")->assertNotFound();
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/{$b}/versions")->assertNotFound();
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/{$b}/entities")->assertNotFound();
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/{$b}/execution")->assertNotFound();
        $this->actingAs($coordA, 'sanctum')->postJson("/api/v1/source-docs/{$b}/validate")->assertNotFound();
        $this->actingAs($coordA, 'sanctum')->get("/api/v1/source-docs/{$b}/render?format=md")->assertNotFound();
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/{$b}/git-url")->assertNotFound();
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/{$b}/compare?from=1&to=2")->assertNotFound();
    }

    // ── auditoria de NEGATIVA nas ações sensíveis (download / git-url) ────────
    public function test_denied_audit_on_sensitive_actions(): void
    {
        $coordA = $this->coordFor($this->custA);
        $b = $this->docB->id;

        $this->actingAs($coordA, 'sanctum')->get("/api/v1/source-docs/{$b}/render?format=md")->assertNotFound();
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/{$b}/git-url")->assertNotFound();

        $this->assertDatabaseHas('source_doc_action_log', [
            'source_doc_id' => $b, 'action' => 'download', 'status' => 'denied', 'actor_user_id' => $coordA->id,
        ]);
        $this->assertDatabaseHas('source_doc_action_log', [
            'source_doc_id' => $b, 'action' => 'view_git', 'status' => 'denied', 'actor_user_id' => $coordA->id,
        ]);
    }

    // ── busca e autocomplete não vazam entidades de outro cliente ────────────
    public function test_search_and_suggest_do_not_leak(): void
    {
        $coordA = $this->coordFor($this->custA);

        // busca a tabela que só existe no cliente B → não retorna nada para o coordenador de A
        $resp = $this->actingAs($coordA, 'sanctum')->getJson('/api/v1/source-docs/search?entity=table&q=SC5010&match=exact')->assertOk();
        $this->assertSame(0, $resp->json('pagination.total'));

        // a tabela do próprio cliente A aparece
        $respA = $this->actingAs($coordA, 'sanctum')->getJson('/api/v1/source-docs/search?entity=table&q=SC2010&match=exact')->assertOk();
        $this->assertSame(1, $respA->json('pagination.total'));

        // autocomplete não sugere o campo do cliente B
        $sug = $this->actingAs($coordA, 'sanctum')->getJson('/api/v1/source-docs/search/suggest?entity=field&q=Field')->assertOk();
        $names = $sug->json('data');
        $this->assertContains('FieldA', $names);
        $this->assertNotContains('FieldB', $names);
    }

    // ── executivo enxerga só os clientes onde é o executivo ──────────────────
    public function test_executivo_scope(): void
    {
        $exec = User::factory()->create(['type' => 'coordenador']);
        $this->custA->update(['executive_id' => $exec->id]);
        $ids = collect($this->actingAs($exec, 'sanctum')->getJson('/api/v1/source-docs')->json('data'))->pluck('id')->all();
        $this->assertContains($this->docA->id, $ids);
        $this->assertNotContains($this->docB->id, $ids);
    }

    // ── view_all_customers (interno) → enxerga todos ─────────────────────────
    public function test_internal_view_all_sees_all(): void
    {
        $u = User::factory()->create(['type' => 'coordenador', 'extra_permissions' => ['source_docs.view_all_customers']]);
        $ids = collect($this->actingAs($u, 'sanctum')->getJson('/api/v1/source-docs')->json('data'))->pluck('id')->all();
        $this->assertContains($this->docA->id, $ids);
        $this->assertContains($this->docB->id, $ids);
    }

    // ── ★ cliente externo + view_all indevido → CONTINUA só o próprio (E2E) ──
    public function test_cliente_externo_with_view_all_is_still_scoped_end_to_end(): void
    {
        // cliente externo do cliente A, com view (p/ acessar a tela) e view_all INDEVIDO.
        $cli = User::factory()->create([
            'type' => 'cliente', 'customer_id' => $this->custA->id,
            'extra_permissions' => ['source_docs.view', 'source_docs.view_all_customers'],
        ]);

        $ids = collect($this->actingAs($cli, 'sanctum')->getJson('/api/v1/source-docs')->json('data'))->pluck('id')->all();
        $this->assertContains($this->docA->id, $ids);
        $this->assertNotContains($this->docB->id, $ids, 'cliente externo NUNCA global, mesmo com view_all');

        // e IDOR: não acessa o doc do cliente B
        $this->actingAs($cli, 'sanctum')->getJson("/api/v1/source-docs/{$this->docB->id}")->assertNotFound();
        $this->actingAs($cli, 'sanctum')->getJson("/api/v1/source-docs/{$this->docA->id}")->assertOk();
    }
}
