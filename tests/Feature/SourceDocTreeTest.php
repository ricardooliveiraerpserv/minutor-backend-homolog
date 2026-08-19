<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Central de Fontes — F2 · Árvore do Acervo. Escopo C4a ponta-a-ponta (admin × escopado × cliente
 * externo × IDOR) + derivação de diretórios do path Git (espaço/acento/profundidade preservados).
 */
class SourceDocTreeTest extends TestCase
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
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false]);

        $this->custA = Customer::factory()->create(['name' => 'AUSTER']);
        $this->custB = Customer::factory()->create(['name' => 'PROMAX']);
        foreach ([
            'Protheus/Faturamento/Pedidos/TMKR03.PRX',
            'Protheus/Faturamento/Pedidos/PEDVEN.PRW',
            'Protheus/Faturamento/Notas Fiscais/NF.PRW', // espaço
            'Protheus/Atualizações/UPD.PRW',             // acento
            'RAIZ.PRW',                                  // arquivo de raiz
        ] as $p) {
            $this->makeDoc($this->custA->id, 'repoA', $p);
        }
        $this->makeDoc($this->custB->id, 'repoB', 'X/Y.PRW');
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

    private function makeDoc(int $customerId, string $repo, string $path): SourceDoc
    {
        $doc = SourceDoc::create([
            'owner' => 'erpserv-clientes', 'repository' => $repo, 'branch' => 'main',
            'path' => $path, 'filename' => basename($path), 'lang' => 'advpl', 'tipo' => 'protheus',
            'analysis_status' => 'partial', 'customer_id' => $customerId,
        ]);
        $ver = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(), 'source_blob_sha' => 'b' . uniqid(),
            'analysis_status' => 'partial', 'deterministic_json' => ['functions' => []],
        ]);
        $doc->forceFill(['current_version_id' => $ver->id])->save();
        return $doc->refresh();
    }

    private function coordFor(Customer $c): User
    {
        $u = User::factory()->create(['type' => 'coordenador']);
        $proj = Project::factory()->create(['customer_id' => $c->id]);
        DB::table('project_coordinators')->insert([
            'project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $u;
    }

    // ── escopo ────────────────────────────────────────────────────────────────
    public function test_admin_sees_all_customers(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $names = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/tree/customers')->json('data'))->pluck('name');
        $this->assertContains('AUSTER', $names);
        $this->assertContains('PROMAX', $names);
    }

    public function test_scoped_user_sees_only_own(): void
    {
        $coordA = $this->coordFor($this->custA);
        $names = collect($this->actingAs($coordA, 'sanctum')->getJson('/api/v1/source-docs/tree/customers')->json('data'))->pluck('name');
        $this->assertContains('AUSTER', $names);
        $this->assertNotContains('PROMAX', $names);
    }

    public function test_external_client_sees_only_own(): void
    {
        $cli = User::factory()->create([
            'type' => 'cliente', 'customer_id' => $this->custA->id,
            'extra_permissions' => ['source_docs.view'], // permissão p/ acessar a Central (escopo continua sendo o próprio)
        ]);
        $names = collect($this->actingAs($cli, 'sanctum')->getJson('/api/v1/source-docs/tree/customers')->json('data'))->pluck('name');
        $this->assertSame(['AUSTER'], $names->all());
    }

    public function test_idor_repos_out_of_scope_404(): void
    {
        $coordA = $this->coordFor($this->custA);
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/tree/customers/{$this->custB->id}/repos")->assertNotFound();
        // o próprio responde
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/tree/customers/{$this->custA->id}/repos")->assertOk();
    }

    public function test_idor_nodes_out_of_scope_404(): void
    {
        $coordA = $this->coordFor($this->custA);
        $this->actingAs($coordA, 'sanctum')
            ->getJson("/api/v1/source-docs/tree/nodes?customer_id={$this->custB->id}&repository=repoB")
            ->assertNotFound();
    }

    // ── derivação de diretórios do path ─────────────────────────────────────────
    public function test_nodes_derives_dirs_and_root_file(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $root = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/source-docs/tree/nodes?customer_id={$this->custA->id}&repository=repoA&path=")
            ->assertOk()->json('data');
        $dirNames = collect($root['dirs'])->pluck('name');
        $fileNames = collect($root['files'])->pluck('name');
        $this->assertContains('Protheus', $dirNames);      // subpasta
        $this->assertContains('RAIZ.PRW', $fileNames);      // arquivo de raiz
        $this->assertSame(4, collect($root['dirs'])->firstWhere('name', 'Protheus')['fontes']); // 4 sob Protheus
    }

    public function test_nodes_preserves_space_and_accent(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $fat = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/source-docs/tree/nodes?customer_id=' . $this->custA->id . '&repository=repoA&path=' . rawurlencode('Protheus/Faturamento'))
            ->assertOk()->json('data');
        $dirNames = collect($fat['dirs'])->pluck('name');
        $this->assertContains('Pedidos', $dirNames);
        $this->assertContains('Notas Fiscais', $dirNames); // espaço preservado

        $prot = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/source-docs/tree/nodes?customer_id=' . $this->custA->id . '&repository=repoA&path=Protheus')
            ->assertOk()->json('data');
        $this->assertContains('Atualizações', collect($prot['dirs'])->pluck('name')); // acento preservado
    }

    public function test_knowledge_aggregates_and_scope(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $d = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/source-docs/tree/knowledge?customer_id={$this->custA->id}&repository=repoA")->assertOk()->json('data');
        $this->assertSame(5, $d['fontes']);
        $this->assertArrayHasKey('cobertura_semantica', $d);
        $this->assertArrayHasKey('saude', $d);
        $this->assertArrayHasKey('cross_source', $d);
        // dir scope (recursivo sob Protheus/Faturamento) = 3 fontes
        $dir = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/tree/knowledge?customer_id=' . $this->custA->id . '&repository=repoA&path=' . rawurlencode('Protheus/Faturamento'))->assertOk()->json('data');
        $this->assertSame(3, $dir['fontes']);
        // IDOR
        $coordA = $this->coordFor($this->custA);
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/source-docs/tree/knowledge?customer_id={$this->custB->id}")->assertNotFound();
    }

    public function test_knowledge_search_and_repository_scope(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        // fonte com semântica contendo termo que NÃO está no nome/path
        $doc = SourceDoc::create(['owner' => 'erpserv-clientes', 'repository' => 'repoA', 'branch' => 'main', 'path' => 'Protheus/Especial/ESP.PRW', 'filename' => 'ESP.PRW', 'lang' => 'advpl', 'tipo' => 'protheus', 'analysis_status' => 'partial', 'customer_id' => $this->custA->id]);
        $ver = SourceDocVersion::create(['source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(), 'source_blob_sha' => 'b' . uniqid(), 'analysis_status' => 'partial', 'deterministic_json' => ['functions' => []], 'semantic_json' => ['objetivo' => 'Rotina de conciliação bancária CNAB']]);
        $doc->forceFill(['current_version_id' => $ver->id])->save();

        // busca por CONHECIMENTO acha pelo texto do semantic_json
        $found = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs?in=knowledge&q=' . rawurlencode('conciliação') . '&customer_id=' . $this->custA->id)->json('data'))->pluck('id')->all();
        $this->assertContains($doc->id, $found);
        // SEM in=knowledge (só nome/path) NÃO acha
        $plain = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs?q=' . rawurlencode('conciliação') . '&customer_id=' . $this->custA->id)->json('data'))->pluck('id')->all();
        $this->assertNotContains($doc->id, $plain);
        // escopo por repository
        $inB = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs?repository=repoB&customer_id=' . $this->custA->id)->json('data'))->pluck('id')->all();
        $this->assertNotContains($doc->id, $inB);
    }

    public function test_repos_lists_repo_with_counts(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $repos = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/source-docs/tree/customers/{$this->custA->id}/repos")->assertOk()->json('data');
        $repoA = collect($repos)->firstWhere('repository', 'repoA');
        $this->assertNotNull($repoA);
        $this->assertSame(5, $repoA['fontes']);
        $this->assertSame('main', $repoA['branch']);
    }
}
