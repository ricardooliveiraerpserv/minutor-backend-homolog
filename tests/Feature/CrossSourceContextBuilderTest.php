<?php

namespace Tests\Feature;

use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\SourceCode\CrossSourceContextBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 3 — materialização BOUNDED do contexto cross-source (facts-first) + context_fingerprint.
 * Flag OFF ⇒ neutro (comportamento atual). ON ⇒ só resolved, facts do alvo, fingerprint determinístico.
 */
class CrossSourceContextBuilderTest extends TestCase
{
    use DatabaseTransactions;

    private int $dep;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config([
            'services.source_doc_ai.cross_source.inject_enabled' => true,
            'services.source_doc_ai.context_resolver.utility_patterns' => ['grava?log'],
            'services.source_doc_ai.context_resolver.relevance_min' => 0.30,
            'services.source_doc_ai.context_resolver.max_context_sources' => 3,
        ]);

        // alvo define U_CCSCOM01 (SC7.C7_NUM) — facts materializáveis; blob 'blobT'.
        $t = SourceDoc::create(['owner' => 'cli', 'repository' => 'r1', 'branch' => 'main', 'path' => 'CCSCOM01.prw', 'filename' => 'CCSCOM01.prw']);
        $tv = SourceDocVersion::create(['source_doc_id' => $t->id, 'source_commit_sha' => 'ct', 'source_blob_sha' => 'blobT',
            'deterministic_json' => ['functions' => [['name' => 'CCSCOM01', 'start_line' => 20, 'end_line' => 125, 'calls_user' => []]],
                'tables' => [['table' => 'SC7', 'access' => ['READ'], 'read_fields' => ['C7_NUM'], 'functions' => ['CCSCOM01']]]]]);
        $t->update(['current_version_id' => $tv->id]);
        DB::table('source_symbol_definition')->insert([
            'symbol_norm' => 'ccscom01', 'source_doc_id' => $t->id, 'blob_sha' => 'blobT', 'owner' => 'cli', 'repository' => 'r1',
            'function_name' => 'CCSCOM01', 'start_line' => 20, 'end_line' => 125, 'is_user_function' => true, 'writes' => true,
            'touches_tables' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // dependente chama U_CCSCOM01
        $d = SourceDoc::create(['owner' => 'cli', 'repository' => 'r1', 'branch' => 'main', 'path' => 'PO.tlpp', 'filename' => 'PO.tlpp']);
        $dv = SourceDocVersion::create(['source_doc_id' => $d->id, 'source_commit_sha' => 'cd', 'source_blob_sha' => 'blobD',
            'deterministic_json' => ['user_calls' => ['U_CCSCOM01'], 'functions' => [['name' => 'MAIN']],
                'dependencies' => ['internal_functions' => ['MAIN'], 'totvs_framework_functions' => []]]]);
        $d->update(['current_version_id' => $dv->id]);
        $this->dep = $d->id;
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function doc(): SourceDoc { return SourceDoc::with('currentVersion')->find($this->dep); }

    public function test_build_materializes_facts_first_with_fingerprint(): void
    {
        $r = app(CrossSourceContextBuilder::class)->build($this->doc());
        $this->assertTrue($r['enabled']);
        $this->assertNotSame('', $r['fingerprint']);
        $this->assertCount(1, $r['sources']);
        $s = $r['sources'][0];
        $this->assertSame('ccscom01', $s['symbol']);
        $this->assertSame('blobT', $s['blob_sha']);
        $this->assertTrue($s['facts_included']);
        $this->assertFalse($s['snippet_included'], 'facts-first: snippet não é requisito');
        $this->assertSame('CCSCOM01', $s['facts']['function']);        // facts REAIS do alvo
        $this->assertSame('SC7', $s['facts']['tables'][0]['table']);   // tabela do alvo materializada
    }

    public function test_flag_off_is_neutral(): void
    {
        config(['services.source_doc_ai.cross_source.inject_enabled' => false]);
        $r = app(CrossSourceContextBuilder::class)->build($this->doc());
        $this->assertFalse($r['enabled']);
        $this->assertSame('', $r['fingerprint'], 'OFF ⇒ fingerprint neutro (cache/comportamento atual)');
        $this->assertSame([], $r['sources']);
    }

    public function test_fingerprint_is_deterministic_and_context_sensitive(): void
    {
        $b = app(CrossSourceContextBuilder::class);
        $ctxB = [['symbol' => 'ccscom01', 'blob_sha' => 'blobT', 'snippet_included' => false]];
        $ctxC = [['symbol' => 'ccscom01', 'blob_sha' => 'blobDIFERENTE', 'snippet_included' => false]];
        $this->assertSame($b->fingerprint($ctxB), $b->fingerprint($ctxB), 'determinístico');
        $this->assertNotSame($b->fingerprint($ctxB), $b->fingerprint($ctxC), 'blob de contexto diferente → fingerprint diferente');
        $this->assertSame('', $b->fingerprint([]), 'sem contexto → neutro');
    }
}
