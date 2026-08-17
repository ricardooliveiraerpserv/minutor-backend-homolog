<?php

namespace Tests\Feature;

use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\SourceCode\CrossSourceEvidenceValidator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cross-source Fase 2 — o juiz determinístico só aceita evidência externa (level C) quando TODAS as
 * verificações passam (edge resolved + alvo + blob EXATO + símbolo nos facts + relation). P0 = blob.
 */
class CrossSourceEvidenceValidatorTest extends TestCase
{
    use DatabaseTransactions;

    private int $dep;
    private int $target;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        // alvo (tipo doc 206): define U_CCSCOM01, tabela SPED050(campo STATUS), blob 'blobT'.
        $t = SourceDoc::create(['owner' => 'cli', 'repository' => 'r1', 'branch' => 'main', 'path' => 'CCSCOM01.prw', 'filename' => 'CCSCOM01.prw']);
        $tv = SourceDocVersion::create(['source_doc_id' => $t->id, 'source_commit_sha' => 'ct', 'source_blob_sha' => 'blobT',
            'deterministic_json' => ['functions' => [['name' => 'CCSCOM01', 'start_line' => 20, 'end_line' => 125]],
                'tables' => [['table' => 'SPED050', 'write_fields' => ['STATUS']]]]]);
        $t->update(['current_version_id' => $tv->id]);
        // dependente (tipo doc 66)
        $d = SourceDoc::create(['owner' => 'cli', 'repository' => 'r1', 'branch' => 'main', 'path' => 'PO.tlpp', 'filename' => 'PO.tlpp']);
        $this->dep = $d->id; $this->target = $t->id;
        $this->edge('ccscom01', 'resolved', 'calls_user', 'blobT', $t->id);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2).'/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function edge(string $sym, string $state, string $rel, ?string $blob, ?int $target): void
    {
        DB::table('source_semantic_context_edge')->insert([
            'dependent_source_doc_id' => $this->dep, 'target_source_doc_id' => $target, 'symbol' => $sym,
            'relation' => $rel, 'state' => $state, 'target_blob_sha' => $blob, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function v(): CrossSourceEvidenceValidator { return new CrossSourceEvidenceValidator(); }

    private function ev(array $over = []): array
    {
        return array_merge(['source_doc_id' => $this->target, 'blob_sha' => 'blobT', 'symbol' => 'U_CCSCOM01',
            'relation' => 'calls_user', 'evidence_type' => 'function'], $over);
    }

    public function test_accept_valid_cross_source(): void
    {
        $r = $this->v()->validate($this->ev(), $this->dep);
        $this->assertTrue($r['accepted'], json_encode($r));
        $this->assertSame('C', $r['evidence']['level']);
        $this->assertSame($this->target, $r['evidence']['source_doc_id']);
    }

    public function test_reject_wrong_source_doc_id(): void
    {
        $r = $this->v()->validate($this->ev(['source_doc_id' => 999999]), $this->dep);
        $this->assertFalse($r['accepted']);
        // há edge resolvido p/ o símbolo, mas para OUTRO alvo → não empresta level C ao alvo forjado.
        $this->assertSame('target_mismatch', $r['reason']);
    }

    public function test_reject_symbol_with_no_edge_at_all(): void
    {
        $r = $this->v()->validate($this->ev(['symbol' => 'U_SEMEDGE', 'source_doc_id' => $this->target]), $this->dep);
        $this->assertFalse($r['accepted']);
        $this->assertSame('no_edge_for_symbol', $r['reason']);
    }

    public function test_reject_blob_stale_is_P0(): void
    {
        $r = $this->v()->validate($this->ev(['blob_sha' => 'blobOLD']), $this->dep);
        $this->assertFalse($r['accepted']);
        $this->assertSame('blob_stale', $r['reason'], 'evidência contra versão diferente da atual é rejeitada');
    }

    public function test_reject_symbol_not_in_target(): void
    {
        // edge existe p/ 'inexistente' mas o alvo não define essa função nos facts.
        $this->edge('inexistente', 'resolved', 'calls_user', 'blobT', $this->target);
        $r = $this->v()->validate($this->ev(['symbol' => 'U_INEXISTENTE']), $this->dep);
        $this->assertFalse($r['accepted']);
        $this->assertStringContainsString('symbol_not_in_target', $r['reason']);
    }

    public function test_reject_relation_incompatible(): void
    {
        $r = $this->v()->validate($this->ev(['relation' => 'called_by']), $this->dep);
        $this->assertFalse($r['accepted']);
        $this->assertSame('relation_incompatible', $r['reason']);
    }

    public function test_reject_field_not_in_target(): void
    {
        $this->edge('ccscom01', 'resolved', 'calls_user', 'blobT', $this->target); // já existe; reuse
        $r = $this->v()->validate($this->ev(['evidence_type' => 'field', 'table' => 'SPED050', 'field' => 'NAOEXISTE']), $this->dep);
        $this->assertFalse($r['accepted']);
        $this->assertStringContainsString('symbol_not_in_target:field', $r['reason']);
    }

    public function test_accept_field_in_target(): void
    {
        $r = $this->v()->validate($this->ev(['evidence_type' => 'field', 'table' => 'SPED050', 'field' => 'STATUS']), $this->dep);
        $this->assertTrue($r['accepted'], json_encode($r));
    }

    public function test_ambiguous_never_level_c(): void
    {
        $this->edge('ambsym', 'ambiguous', 'calls_user', null, null);
        $r = $this->v()->validate($this->ev(['symbol' => 'U_AMBSYM']), $this->dep);
        $this->assertFalse($r['accepted']);
        $this->assertSame('edge_ambiguous', $r['reason']);
    }

    public function test_unresolved_never_level_c(): void
    {
        $this->edge('unrsym', 'unresolved', 'calls_user', null, null);
        $r = $this->v()->validate($this->ev(['symbol' => 'U_UNRSYM', 'source_doc_id' => $this->target]), $this->dep);
        $this->assertFalse($r['accepted']);
    }

    public function test_absence_of_snippet_does_not_block(): void
    {
        // sem start_line/snippet → valida por FACTS mesmo assim.
        $r = $this->v()->validate($this->ev(['start_line' => null]), $this->dep);
        $this->assertTrue($r['accepted']);
    }

    public function test_reject_incomplete_or_no_context(): void
    {
        $this->assertFalse($this->v()->validate(['source_doc_id' => $this->target], $this->dep)['accepted']); // faltam campos
        $this->assertSame('no_dependent_context', $this->v()->validate($this->ev(), null)['reason']); // sem dependente
    }
}
