<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\Models\User;
use App\SourceCode\SourceDocIndexer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Central de Fontes — C2. Prova: o indexer deriva entidades corretas do deterministic_json;
 * a busca usa SÓ o read-model (nunca faz scan do deterministic_json); stale por version+blob;
 * rebuild idempotente; functions_count do catálogo vem do índice; gate por permissão.
 */
class SourceDocSearchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach ([
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw,
        ] as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
        parent::setUp();
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function admin(): User    { return User::factory()->create(['type' => 'admin']); }
    private function consultor(): User { return User::factory()->create(['type' => 'consultor']); }

    private function det(): array
    {
        return [
            'functions' => [
                ['name' => 'IMPPCP', 'type' => 'Function', 'evidence' => ['line_start' => 10, 'line_end' => 50]],
                ['name' => 'CCSPCP03', 'type' => 'User Function', 'evidence' => ['line_start' => 159, 'line_end' => 226]],
            ],
            'tables' => [
                ['table' => 'SC2', 'access' => ['READ', 'UPDATE'], 'fields' => ['C2_NUM', 'C2_STATUS']],
            ],
            'queries' => [
                ['table' => 'BETONMIX', 'fields' => ['STATUS'], 'operation' => 'SELECT', 'function' => 'IMPPCP',
                 'risk_flags' => ['dynamic_sql_by_concatenation'], 'evidence' => ['line_start' => 277, 'line_end' => 396]],
            ],
            'integrations' => [['host' => 'api.sefaz.gov']],
            'dependencies' => ['RWMAKE'],
            'security_findings' => [['type' => 'sql_injection']],
        ];
    }

    private function makeIndexedDoc(?int $customerId = null): SourceDoc
    {
        $doc = SourceDoc::create([
            'owner' => 'erpserv-clientes', 'repository' => 'concreserv', 'branch' => 'main',
            'path' => 'x/' . uniqid() . '.prw', 'filename' => 'CCSPCP03.PRW', 'lang' => 'advpl',
            'tipo' => 'protheus', 'analysis_status' => 'completed', 'customer_id' => $customerId,
        ]);
        $ver = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(),
            'source_blob_sha' => 'blob' . uniqid(), 'analysis_status' => 'completed',
            'deterministic_json' => $this->det(), 'semantic_json' => ['objetivo' => 'x'],
        ]);
        $doc->forceFill(['current_version_id' => $ver->id])->save();
        app(SourceDocIndexer::class)->index($doc->refresh());
        return $doc;
    }

    // ── indexer ───────────────────────────────────────────────────────────────

    public function test_indexer_extracts_expected_entities(): void
    {
        $doc = $this->makeIndexedDoc();
        $rows = DB::table('source_doc_entities')->where('source_doc_id', $doc->id)->get();
        $byType = $rows->groupBy('entity_type')->map->count();

        $this->assertSame(2, $byType['function'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $byType['table'] ?? 0);   // SC2 + BETONMIX
        $this->assertGreaterThanOrEqual(3, $byType['field'] ?? 0);   // C2_NUM,C2_STATUS,STATUS
        $this->assertGreaterThanOrEqual(1, $byType['query'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $byType['risk'] ?? 0);    // dynamic_sql + sql_injection
        $this->assertGreaterThanOrEqual(1, $byType['integration'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $byType['dependency'] ?? 0);

        // summary
        $idx = DB::table('source_doc_index')->where('source_doc_id', $doc->id)->first();
        $this->assertSame(2, $idx->functions_count);
        $this->assertTrue((bool) $idx->has_risk);
    }

    public function test_stale_rule_by_version_and_blob(): void
    {
        $doc = $this->makeIndexedDoc();
        $indexer = app(SourceDocIndexer::class);
        $this->assertFalse($indexer->isStale($doc->refresh()));

        // Nova versão vigente (novo blob) → STALE.
        $v2 = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'c2' . uniqid(),
            'source_blob_sha' => 'blobNOVO' . uniqid(), 'analysis_status' => 'completed',
            'deterministic_json' => $this->det(),
        ]);
        $doc->forceFill(['current_version_id' => $v2->id])->save();
        $this->assertTrue($indexer->isStale($doc->refresh()));
    }

    public function test_rebuild_is_idempotent(): void
    {
        $doc = $this->makeIndexedDoc();
        $n1 = DB::table('source_doc_entities')->where('source_doc_id', $doc->id)->count();
        app(SourceDocIndexer::class)->index($doc->refresh());
        $n2 = DB::table('source_doc_entities')->where('source_doc_id', $doc->id)->count();
        $this->assertSame($n1, $n2, 'reindex não deve duplicar linhas');
    }

    // ── busca ────────────────────────────────────────────────────────────────

    public function test_search_by_table_field_access_function_risk(): void
    {
        $doc = $this->makeIndexedDoc();
        $admin = $this->admin();

        // quem usa SC2
        $r = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/search?entity=table&q=SC2&match=exact');
        $r->assertOk();
        $this->assertContains($doc->id, array_column($r->json('data'), 'source_doc') ? array_map(fn ($x) => $x['source_doc']['id'], $r->json('data')) : []);

        // quem ESCREVE (UPDATE) o campo C2_STATUS → encontra
        $up = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/search?entity=field&q=C2_STATUS&match=exact&access=UPDATE');
        $up->assertOk();
        $this->assertSame(1, $up->json('pagination.total'));

        // DELETE em C2_STATUS → não encontra
        $del = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/search?entity=field&q=C2_STATUS&match=exact&access=DELETE');
        $del->assertOk();
        $this->assertSame(0, $del->json('pagination.total'));

        // função
        $fn = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/search?entity=function&q=IMPPCP&match=exact');
        $fn->assertOk()->assertJsonPath('pagination.total', 1);

        // risk
        $rk = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/search?entity=risk&q=dynamic_sql_by_concatenation&match=exact');
        $rk->assertOk()->assertJsonPath('pagination.total', 1);
    }

    public function test_search_reads_only_index_not_deterministic_json(): void
    {
        $this->makeIndexedDoc();
        $admin = $this->admin();
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/search?entity=table&q=SC2')->assertOk();
        $sql = implode(' | ', array_map(fn ($q) => $q['query'], DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertStringNotContainsString('deterministic_json', $sql, 'a busca NÃO pode ler o deterministic_json');
        $this->assertStringContainsString('source_doc_entities', $sql);
    }

    public function test_suggest_autocomplete(): void
    {
        $this->makeIndexedDoc();
        $r = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs/search/suggest?entity=table&q=SC');
        $r->assertOk();
        $this->assertContains('SC2', $r->json('data'));
    }

    public function test_entities_endpoint(): void
    {
        $doc = $this->makeIndexedDoc();
        $r = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/source-docs/{$doc->id}/entities?type=function");
        $r->assertOk();
        $this->assertArrayHasKey('function', $r->json('data'));
    }

    public function test_permission_gate(): void
    {
        $this->makeIndexedDoc();
        $this->actingAs($this->consultor(), 'sanctum')->getJson('/api/v1/source-docs/search?entity=table&q=SC2')->assertForbidden();
    }

    public function test_catalog_functions_count_from_index(): void
    {
        $doc = $this->makeIndexedDoc();
        $r = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs?with_situation=false');
        $r->assertOk();
        $row = collect($r->json('data'))->firstWhere('id', $doc->id);
        $this->assertSame(2, $row['functions_count']); // veio do source_doc_index
    }

    public function test_pagination_total_counts_distinct_docs_not_entity_rows(): void
    {
        // 1 fonte com VÁRIOS campos (C2_NUM, C2_STATUS, STATUS) → busca entity=field deve
        // reportar total=1 (fontes distintas), não o nº de linhas de entidade.
        $this->makeIndexedDoc();
        $r = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs/search?entity=field');
        $r->assertOk();
        $this->assertSame(1, $r->json('pagination.total'));
        $this->assertCount(1, $r->json('data'));
    }

    public function test_invalid_entity_returns_422(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs/search?entity=bogus&q=x')->assertStatus(422);
    }
}
