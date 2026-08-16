<?php

namespace Tests\Feature;

use App\Models\ClientSourceRepo;
use App\Models\Customer;
use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\Models\User;
use App\SourceCode\Exceptions\SourceIntegrationException;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\SourceDocIndexer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Central de Fontes — C1. Prova: gate por permissão (data-driven), catálogo enxuto (sem JSON
 * pesado por linha), indicadores 100% do banco (sem situação Git catalog-wide), situação em bulk
 * (1 árvore por repo, cacheada, degradação segura), ficha-meta sem 'deterministic', documentação
 * pesada sob demanda, e histórico imutável paginado.
 *
 * Roda contra Postgres (SQL real: json_array_length/::jsonb/ilike/filter). O schema do homolog
 * é carregado no banco local minutor_c1test; cada teste roda em transação (rollback).
 */
class SourceDocCatalogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        // Força a conexão pgsql (o controller usa SQL específico do Postgres). Senha lida do .env
        // do worktree — nunca hardcoded. phpunit.xml aponta sqlite; sobrescrevemos antes do boot.
        $pw = $this->envValue('DB_PASSWORD');
        foreach ([
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw,
        ] as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    private function envValue(string $key): string
    {
        $file = dirname(__DIR__, 2) . '/.env';
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) {
                return trim(substr($line, strlen($key) + 1));
            }
        }
        return '';
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function admin(): User        { return User::factory()->create(['type' => 'admin']); }
    private function coordenador(): User   { return User::factory()->create(['type' => 'coordenador']); }
    private function consultor(): User     { return User::factory()->create(['type' => 'consultor']); }
    private function cliente(): User        { return User::factory()->create(['type' => 'cliente']); }

    /** Cria um fonte documentado com versão vigente. */
    private function makeDoc(array $attrs = [], array $verAttrs = [], ?ClientSourceRepo $repo = null): SourceDoc
    {
        $owner  = $attrs['owner']      ?? 'erpserv-clientes';
        $rep    = $attrs['repository'] ?? 'concreserv';
        $branch = $attrs['branch']     ?? 'main';

        $doc = SourceDoc::create(array_merge([
            'owner' => $owner, 'repository' => $rep, 'branch' => $branch,
            'path' => 'src/' . uniqid() . '.prw', 'filename' => 'X.prw',
            'lang' => 'advpl', 'tipo' => 'protheus', 'size_bytes' => 1000,
            'analysis_status' => 'completed', 'source_repo_id' => $repo?->id,
            'customer_id' => $attrs['customer_id'] ?? null,
        ], array_diff_key($attrs, array_flip(['owner', 'repository', 'branch']))));

        $ver = SourceDocVersion::create(array_merge([
            'source_doc_id' => $doc->id,
            'source_commit_sha' => 'c' . uniqid(),
            'source_blob_sha' => 'blob' . uniqid(),
            'analysis_status' => 'completed',
            'deterministic_json' => ['functions' => [['name' => 'F1'], ['name' => 'F2']], 'tables' => []],
            'semantic_json' => ['objetivo' => 'x'],
            'documentation_json' => [
                'identity' => ['filename' => $doc->filename],
                'semantic' => ['objetivo' => 'Faz algo'],
                'deterministic' => ['functions' => [['name' => 'F1'], ['name' => 'F2']], 'tables' => [['name' => 'SPED050']]],
                'diff' => ['change_type' => 'initial'],
            ],
            'gmud_id' => null, 'ticket_number' => 'T-' . rand(1000, 9999), 'responsavel' => 'Fulano',
        ], $verAttrs));

        // Espelha o documentation_json no doc vivo (como faz o pipeline).
        $doc->forceFill(['current_version_id' => $ver->id, 'documentation_json' => $ver->documentation_json])->save();
        $doc->refresh();

        // C2: functions_count do catálogo vem do read-model → indexa o fonte.
        app(SourceDocIndexer::class)->index($doc);

        return $doc->refresh();
    }

    private function fakeAuth(array|\Throwable $tree): GithubAppAuth
    {
        return new class($tree) extends GithubAppAuth {
            public int $calls = 0;
            private $tree;
            public function __construct($tree) { parent::__construct(); $this->tree = $tree; }
            public function treeBlobShas(string $owner, string $repo, string $ref): array
            {
                $this->calls++;
                if ($this->tree instanceof \Throwable) { throw $this->tree; }
                return $this->tree;
            }
        };
    }

    // ── permissão (ajuste #2: data-driven) ─────────────────────────────────────

    public function test_admin_and_coordenador_can_list_others_cannot(): void
    {
        $this->makeDoc();
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs')->assertOk();
        $this->actingAs($this->coordenador(), 'sanctum')->getJson('/api/v1/source-docs')->assertOk();
        $this->actingAs($this->consultor(), 'sanctum')->getJson('/api/v1/source-docs')->assertForbidden();
        $this->actingAs($this->cliente(), 'sanctum')->getJson('/api/v1/source-docs')->assertForbidden();
    }

    // ── catálogo ───────────────────────────────────────────────────────────────

    public function test_index_returns_data_pagination_and_db_only_indicators(): void
    {
        $this->makeDoc(['analysis_status' => 'completed']);
        $this->makeDoc(['analysis_status' => 'partial'], ['semantic_json' => null, 'analysis_status' => 'partial']);

        $r = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs?with_situation=false');
        $r->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'filename', 'functions_count', 'semantic_quality', 'last_change_at', 'situation']],
                'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                'indicators' => ['total', 'by_analysis', 'by_semantic'],
            ]);
        // ajuste #1: NENHUM contador de situação Git catalog-wide nos indicadores.
        $this->assertArrayNotHasKey('by_situation', $r->json('indicators'));
        $this->assertSame(2, $r->json('indicators.total'));
    }

    public function test_list_never_ships_heavy_json_but_gives_functions_count(): void
    {
        $this->makeDoc();
        $r = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs?with_situation=false');
        $row = $r->json('data.0');
        $this->assertSame(2, $row['functions_count']);              // veio do source_doc_index (C2)
        $this->assertContains($row['semantic_quality'], ['completed', 'partial', 'none']);
        $this->assertArrayNotHasKey('deterministic_json', $row);    // nada pesado por linha
        $this->assertArrayNotHasKey('semantic_json', $row);
        $this->assertArrayNotHasKey('documentation_json', $row);
    }

    public function test_filters_customer_status_q_semantic(): void
    {
        $c = Customer::factory()->create();
        $mine = $this->makeDoc(['customer_id' => $c->id, 'filename' => 'FTENVNFE.PRW', 'path' => 'x/FTENVNFE.PRW']);
        $other = $this->makeDoc(['filename' => 'OUTRO.PRW', 'path' => 'y/OUTRO.PRW', 'analysis_status' => 'failed'], ['analysis_status' => 'failed']);

        $admin = $this->admin();
        $byCustomer = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/source-docs?with_situation=false&customer_id={$c->id}");
        $byCustomer->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($mine->id, $byCustomer->json('data.0.id'));

        $byQ = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs?with_situation=false&q=ftenv');
        $byQ->assertOk()->assertJsonCount(1, 'data');

        $byStatus = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs?with_situation=false&analysis_status=failed');
        $byStatus->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($other->id, $byStatus->json('data.0.id'));
    }

    // ── situação em bulk (1 árvore por repo, cache, degradação) ─────────────────

    public function test_situation_bulk_one_tree_per_repo_and_cache(): void
    {
        $repoA = ClientSourceRepo::create(['customer_id' => Customer::factory()->create()->id, 'owner' => 'erpserv-clientes', 'repository' => 'concreserv', 'branch' => 'main', 'active' => true]);
        $repoB = ClientSourceRepo::create(['customer_id' => Customer::factory()->create()->id, 'owner' => 'erpserv-clientes', 'repository' => 'jng', 'branch' => 'main', 'active' => true]);

        $d1 = $this->makeDoc(['repository' => 'concreserv', 'path' => 'a/1.prw'], [], $repoA);
        $d2 = $this->makeDoc(['repository' => 'concreserv', 'path' => 'a/2.prw'], [], $repoA);
        $d3 = $this->makeDoc(['repository' => 'jng', 'path' => 'b/1.prw'], [], $repoB);

        // Árvore devolve o MESMO blob dos documentados → ATUALIZADA.
        $tree = [
            $d1->path => $d1->currentVersion->source_blob_sha,
            $d2->path => $d2->currentVersion->source_blob_sha,
            $d3->path => $d3->currentVersion->source_blob_sha,
        ];
        $fake = $this->fakeAuth($tree);
        $this->app->instance(GithubAppAuth::class, $fake);

        $r = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs');
        $r->assertOk();
        // 3 docs, 2 repositórios distintos → 2 chamadas de árvore (não 3).
        $this->assertSame(2, $fake->calls, 'deve buscar 1 árvore por repo distinto');
        foreach ($r->json('data') as $row) {
            $this->assertSame('ATUALIZADA', $row['situation']['status']);
        }
        // page_situation é rotulado como escopo da página (nunca total do catálogo).
        $this->assertSame('current_page', $r->json('page_situation.scope'));

        // 2ª chamada: árvore vem do cache → 0 novas chamadas ao Git.
        $r2 = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs');
        $r2->assertOk();
        $this->assertSame(2, $fake->calls, 'cache deve evitar novas chamadas');
    }

    public function test_situation_degrades_without_breaking_list(): void
    {
        $repo = ClientSourceRepo::create(['customer_id' => Customer::factory()->create()->id, 'owner' => 'erpserv-clientes', 'repository' => 'concreserv', 'branch' => 'main', 'active' => true]);
        $this->makeDoc(['repository' => 'concreserv', 'path' => 'a/1.prw'], [], $repo);

        $this->app->instance(GithubAppAuth::class, $this->fakeAuth(new SourceIntegrationException('github down', 'github_unavailable')));

        $r = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs');
        $r->assertOk(); // lista NÃO quebra
        $this->assertSame('NAO_VALIDADO', $r->json('data.0.situation.status'));
        $this->assertNotNull($r->json('data.0.situation.reason'));
    }

    // ── anti-N+1 ────────────────────────────────────────────────────────────────

    public function test_no_n_plus_1_query_count_is_constant(): void
    {
        // 3 docs.
        for ($i = 0; $i < 3; $i++) { $this->makeDoc(['path' => "n/{$i}.prw"]); }
        $admin = $this->admin();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs?with_situation=false')->assertOk();
        $q3 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 12 docs — a contagem de queries NÃO deve crescer com o nº de linhas.
        for ($i = 3; $i < 12; $i++) { $this->makeDoc(['path' => "n/{$i}.prw"]); }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs?with_situation=false&per_page=50')->assertOk();
        $q12 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($q3, $q12, "queries devem ser constantes (N+1): {$q3} vs {$q12}");
    }

    // ── ficha meta (ajuste #3: sem 'deterministic') ─────────────────────────────

    public function test_show_meta_excludes_deterministic_and_shows_four_statuses(): void
    {
        // Doc COM repo ATIVO — garante que o show() carrega sourceRepo.active e o resolver
        // resolve ATUALIZADA (regressão do bug repository_inactive por select incompleto).
        $repo = ClientSourceRepo::create(['customer_id' => Customer::factory()->create()->id, 'owner' => 'erpserv-clientes', 'repository' => 'concreserv', 'branch' => 'main', 'active' => true]);
        $doc = $this->makeDoc(['repository' => 'concreserv'], [], $repo);
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth([$doc->path => $doc->currentVersion->source_blob_sha]));

        $r = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/source-docs/{$doc->id}");
        $r->assertOk()
            ->assertJsonPath('data.situation.status', 'ATUALIZADA')                 // 1) doc
            ->assertJsonPath('data.current_version.semantic_quality', 'completed')  // 2) semântica
            ->assertJsonPath('data.analysis_status', 'completed');
        $this->assertNotNull($r->json('data.current_version.created_at'));           // 3) última GMUD/alteração
        $this->assertNotNull($r->json('data.situation.checked_at'));                 // 4) última validação

        $meta = $r->json('data.documentation_meta');
        $this->assertArrayHasKey('semantic', $meta);
        $this->assertArrayNotHasKey('deterministic', $meta, 'meta NÃO pode trazer o bloco pesado');
    }

    public function test_show_404_for_unknown(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs/999999')->assertNotFound();
    }

    public function test_documentation_returns_deterministic_on_demand(): void
    {
        $doc = $this->makeDoc();
        $r = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/source-docs/{$doc->id}/documentation");
        $r->assertOk();
        $this->assertArrayHasKey('functions', $r->json('data.deterministic'));
    }

    // ── histórico ────────────────────────────────────────────────────────────────

    public function test_versions_paginated_desc(): void
    {
        $doc = $this->makeDoc();
        // acrescenta 2 versões antigas (não são a vigente)
        SourceDocVersion::create(['source_doc_id' => $doc->id, 'source_commit_sha' => 'old1', 'analysis_status' => 'completed', 'ticket_number' => 'T-1']);
        SourceDocVersion::create(['source_doc_id' => $doc->id, 'source_commit_sha' => 'old2', 'analysis_status' => 'completed', 'ticket_number' => 'T-2']);

        $r = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/source-docs/{$doc->id}/versions");
        $r->assertOk()->assertJsonStructure(['data' => [['id', 'source_commit_sha', 'analysis_status', 'diff_summary', 'created_at']], 'current_page', 'total']);
        $ids = array_column($r->json('data'), 'id');
        $sorted = $ids; rsort($sorted);
        $this->assertSame($sorted, $ids, 'mais recente primeiro');
        $this->assertSame(3, $r->json('total'));
    }
}
