<?php

namespace Tests\Feature;

use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\SourceCode\SourceContextResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cross-source Fase 1 — resolução determinística: repo-first, dedup por blob, estados
 * resolved/ambiguous/unresolved, relevância auditável, bounded. SEM IA.
 */
class SourceContextResolverTest extends TestCase
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
        config(['services.source_doc_ai.context_resolver.utility_patterns' => ['grava?log', '^log', 'isrunning'],
            'services.source_doc_ai.context_resolver.relevance_min' => 0.30,
            'services.source_doc_ai.context_resolver.max_context_sources' => 3]);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2).'/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function makeDoc(string $repo, string $path, array $userCalls, array $internalFns = []): SourceDoc
    {
        $doc = SourceDoc::create(['owner' => 'cli', 'repository' => $repo, 'branch' => 'main', 'path' => $path, 'filename' => basename($path)]);
        $ver = SourceDocVersion::create(['source_doc_id' => $doc->id, 'source_commit_sha' => 'c'.$doc->id,
            'deterministic_json' => ['user_calls' => $userCalls, 'functions' => array_map(fn ($n) => ['name' => $n], $internalFns),
                'dependencies' => ['internal_functions' => $internalFns, 'totvs_framework_functions' => ['MsgStop', 'TCSQLExec']]]]);
        $doc->update(['current_version_id' => $ver->id]);
        return $doc->fresh('currentVersion');
    }

    private function def(string $repo, int $docId, string $fn, string $blob, bool $writes = true, int $tabs = 3): void
    {
        DB::table('source_symbol_definition')->insert([
            'symbol_norm' => strtolower(ltrim(preg_replace('/^u_/i', '', $fn), '')), 'source_doc_id' => $docId, 'blob_sha' => $blob,
            'owner' => 'cli', 'repository' => $repo, 'function_name' => $fn, 'start_line' => 10, 'end_line' => 40,
            'is_user_function' => true, 'writes' => $writes, 'touches_tables' => $tabs, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_self_contained_returns_empty(): void
    {
        $doc = $this->makeDoc('r1', 'A.prw', ['U_A'], ['A']); // chama só a si
        $r = app(SourceContextResolver::class)->resolve($doc);
        $this->assertSame(0, $r['telemetry']['outbound_symbols']);
        $this->assertEmpty($r['resolved']);
        $this->assertEmpty($r['context_sources']);
    }

    public function test_resolved_relevant_enters_context(): void
    {
        $doc = $this->makeDoc('r1', 'A.prw', ['U_FOO'], ['A']);
        $this->def('r1', 999001, 'U_FOO', 'blobFOO', writes: true, tabs: 4);
        $r = app(SourceContextResolver::class)->resolve($doc);
        $this->assertCount(1, $r['resolved']);
        $this->assertSame(999001, $r['resolved'][0]['target_doc_id']);
        $this->assertTrue($r['resolved'][0]['relevant']);
        $this->assertCount(1, $r['context_sources'], 'resolved+relevante entra no contexto');
    }

    public function test_ambiguous_not_resolved(): void
    {
        $doc = $this->makeDoc('r1', 'A.prw', ['U_FOO'], ['A']);
        $this->def('r1', 999002, 'U_FOO', 'blobX'); // 2 blobs DIFERENTES, mesmo repo
        $this->def('r1', 999003, 'U_FOO', 'blobY');
        $r = app(SourceContextResolver::class)->resolve($doc);
        $this->assertCount(1, $r['ambiguous']);
        $this->assertEmpty($r['resolved']);
        $this->assertEmpty($r['context_sources'], 'ambíguo NÃO sustenta contexto forte');
    }

    public function test_duplicate_blob_dedups_to_resolved(): void
    {
        $doc = $this->makeDoc('r1', 'A.prw', ['U_FOO'], ['A']);
        $this->def('r1', 999004, 'U_FOO', 'blobSAME'); // MESMO blob (arquivo duplicado no acervo)
        $this->def('r1', 999005, 'U_FOO', 'blobSAME');
        $r = app(SourceContextResolver::class)->resolve($doc);
        $this->assertCount(1, $r['resolved'], 'dedup por blob → 1 candidato canônico → resolved');
        $this->assertSame(1, $r['resolved'][0]['target_doc_id'] === 999004 || $r['resolved'][0]['target_doc_id'] === 999005 ? 1 : 0);
        $this->assertTrue($r['resolved'][0]['relevant']);
    }

    public function test_unresolved_when_no_definer(): void
    {
        $doc = $this->makeDoc('r1', 'A.prw', ['U_BAR'], ['A']);
        $r = app(SourceContextResolver::class)->resolve($doc);
        $this->assertSame(['bar'], $r['unresolved']);
        $this->assertEmpty($r['context_sources']);
    }

    public function test_utility_is_discarded_with_reason(): void
    {
        $doc = $this->makeDoc('r1', 'A.prw', ['U_FGRAVALOG'], ['A']);
        $this->def('r1', 999006, 'U_FGRAVALOG', 'blobLOG', writes: true, tabs: 2);
        $r = app(SourceContextResolver::class)->resolve($doc);
        $this->assertCount(1, $r['resolved']);
        $this->assertFalse($r['resolved'][0]['relevant']);
        $this->assertSame('utility_blocklist', $r['resolved'][0]['reason']);
        $this->assertEmpty($r['context_sources'], 'utilitário não entra no contexto');
        $this->assertNotEmpty($r['discarded']);
        $this->assertSame('utility_blocklist', $r['discarded'][0]['reason_discarded']);
    }

    public function test_facts_first_keeps_resolved_when_snippet_too_big(): void
    {
        // função-alvo ENORME (L10-6000) → snippet > cap. Facts-first: resolved ENTRA com facts; snippet fora.
        $doc = $this->makeDoc('r1', 'A.prw', ['U_BIG'], ['A']);
        DB::table('source_symbol_definition')->insert([
            'symbol_norm' => 'big', 'source_doc_id' => 999009, 'blob_sha' => 'blobBIG', 'owner' => 'cli', 'repository' => 'r1',
            'function_name' => 'U_BIG', 'start_line' => 10, 'end_line' => 6000, 'is_user_function' => true, 'writes' => true,
            'touches_tables' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $r = app(SourceContextResolver::class)->resolve($doc);
        $this->assertCount(1, $r['resolved']);
        $this->assertCount(1, $r['context_sources'], 'resolved NÃO é descartado por snippet grande');
        $cs = $r['context_sources'][0];
        $this->assertTrue($cs['facts_included']);
        $this->assertFalse($cs['snippet_included'], 'snippet grande fica de fora');
        $this->assertSame('over_budget', $cs['snippet_skipped_reason']);
        $this->assertSame(120, $cs['estimated_context_tokens'], 'só facts');
        $this->assertSame([], array_filter($r['discarded'], fn ($x) => str_contains((string) $x['reason_discarded'], 'over_max_tokens')), 'não vira descarte por tokens');
    }

    public function test_repo_first_prefers_same_repo(): void
    {
        $doc = $this->makeDoc('r1', 'A.prw', ['U_FOO'], ['A']);
        $this->def('r1', 999007, 'U_FOO', 'blobR1'); // mesmo repo
        $this->def('r2', 999008, 'U_FOO', 'blobR2'); // outro repo (deve ser ignorado no escopo repo)
        $r = app(SourceContextResolver::class)->resolve($doc);
        $this->assertCount(1, $r['resolved']);
        $this->assertSame('repo', $r['resolved'][0]['scope']);
        $this->assertSame(999007, $r['resolved'][0]['target_doc_id']);
    }
}
