<?php

namespace Tests\Feature;

use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\SourceCode\Analyzer\SourceDocAiProvider;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 3 — GATE NEGATIVO end-to-end: contexto externo NÃO contamina a documentação. Mesmo que a IA
 * EMITA evidência cross-source, ela só sobrevive se passar pelo validador determinístico (Fase 2).
 * blob errado / source_doc_id errado / símbolo inexistente / relation incompatível → REJEITADAS e
 * removidas da saída; a legítima é aceita (level C) e rastreável na proveniência.
 */
class CrossSourceContaminationTest extends TestCase
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
        config([
            'services.source_doc_ai.enabled' => true,
            'services.source_doc_ai.environment' => 'homolog',
            'services.source_doc_ai.allowed_environments' => ['homolog'],
            'services.source_doc_ai.cache_enabled' => false,
            'services.source_doc_ai.simple_route_enabled' => true,
            'services.source_doc_ai.simple_max_functions' => 5,
            'services.source_doc_ai.hard_limit_usd' => 0.30,
        ]);

        $t = SourceDoc::create(['owner' => 'cli', 'repository' => 'r1', 'branch' => 'main', 'path' => 'CCSCOM01.prw', 'filename' => 'CCSCOM01.prw']);
        $tv = SourceDocVersion::create(['source_doc_id' => $t->id, 'source_commit_sha' => 'ct', 'source_blob_sha' => 'blobT',
            'deterministic_json' => ['functions' => [['name' => 'CCSCOM01', 'start_line' => 20, 'end_line' => 125]],
                'tables' => [['table' => 'SC7', 'read_fields' => ['C7_NUM'], 'functions' => ['CCSCOM01']]]]]);
        $t->update(['current_version_id' => $tv->id]);
        $this->target = $t->id;

        $d = SourceDoc::create(['owner' => 'cli', 'repository' => 'r1', 'branch' => 'main', 'path' => 'PO.tlpp', 'filename' => 'PO.tlpp']);
        $this->dep = $d->id;
        DB::table('source_semantic_context_edge')->insert([
            'dependent_source_doc_id' => $this->dep, 'target_source_doc_id' => $this->target, 'symbol' => 'ccscom01',
            'relation' => 'calls_user', 'state' => 'resolved', 'target_blob_sha' => 'blobT', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function det(): array
    {
        return [
            'source_type' => 'Fonte Protheus', 'language' => 'AdvPL', 'file' => ['filename' => 'PO.tlpp'],
            'functions' => [['name' => 'MAIN', 'type' => 'User Function', 'start_line' => 1, 'end_line' => 8, 'params' => [], 'calls_user' => ['U_CCSCOM01'], 'tables' => [], 'evidence' => ['line_start' => 1, 'line_end' => 8]]],
            'tables' => [], 'queries' => [], 'user_calls' => ['U_CCSCOM01'], 'dependencies' => [], 'security_findings' => [],
        ];
    }

    /** Resposta simples da IA com regras carregando evidência cross-source (1 legítima + 4 adulteradas). */
    private function response(): string
    {
        $x = fn (array $o) => array_merge(['type' => 'cross_source', 'source_doc_id' => $this->target,
            'blob_sha' => 'blobT', 'symbol' => 'U_CCSCOM01', 'relation' => 'calls_user', 'evidence_type' => 'function'], $o);

        return json_encode([
            'entendimento_funcional' => [
                'uma_frase' => ['texto' => 'Orquestra pedido de compra.', 'confidence' => 'medium', 'evidence' => [['type' => 'function', 'name' => 'MAIN']]],
                'objetivo' => 'Cria pedido e delega ao cálculo.', 'quando_usado' => 'No pedido.', 'o_que_faz' => [],
            ],
            'regras_negocio' => [
                ['id' => 'X-OK',   'descricao' => 'Delegacao legitima ao calculo externo.', 'confidence' => 'high', 'evidence' => [$x([])]],
                ['id' => 'X-BLOB', 'descricao' => 'Depende de versao superada do alvo.',    'confidence' => 'high', 'evidence' => [$x(['blob_sha' => 'blobANTIGO'])]],
                ['id' => 'X-DOC',  'descricao' => 'Aponta doc inexistente.',                 'confidence' => 'high', 'evidence' => [$x(['source_doc_id' => 999999])]],
                ['id' => 'X-SYM',  'descricao' => 'Simbolo inexistente.',                    'confidence' => 'high', 'evidence' => [$x(['symbol' => 'U_NAOEXISTE'])]],
                ['id' => 'X-REL',  'descricao' => 'Relacao incompativel.',                   'confidence' => 'high', 'evidence' => [$x(['relation' => 'called_by'])]],
            ],
            'change_summary' => 'inicial',
        ]);
    }

    private function ai(): SourceDocAiProvider
    {
        $resp = $this->response();
        return new class($resp) implements SourceDocAiProvider {
            public function __construct(private string $r) {}
            public function isConfigured(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function model(): string { return 'fake-1'; }
            public function complete(string $system, string $user, array $opts = []): array
            {
                return ['text' => $this->r, 'usage' => ['input_tokens' => 100, 'output_tokens' => 50], 'stop' => 'end_turn'];
            }
        };
    }

    public function test_tampered_cross_source_evidence_cannot_contaminate(): void
    {
        $sem = (new SourceDocSemanticAnalyzer($this->ai()))->analyze(
            $this->det(), 'codigo', null, ['source_doc_id' => $this->dep, 'blob_sha' => 'blobD']
        );

        $xs = $sem['cross_source'] ?? [];
        // 1 legítima ACEITA (level C, rastreável); 4 adulteradas REJEITADAS.
        $this->assertCount(1, $xs['evidence_accepted'] ?? [], 'só a evidência íntegra vira fato');
        $this->assertSame('C', $xs['evidence_accepted'][0]['level']);
        $this->assertSame($this->target, $xs['evidence_accepted'][0]['source_doc_id']);

        $reasons = array_column($xs['evidence_rejected'] ?? [], 'reason');
        $this->assertContains('blob_stale', $reasons, 'blob errado (versão superada) rejeitado — P0');
        $this->assertContains('target_mismatch', $reasons, 'source_doc_id errado rejeitado');
        $this->assertContains('no_edge_for_symbol', $reasons, 'símbolo inexistente rejeitado');
        $this->assertContains('relation_incompatible', $reasons, 'relation incompatível rejeitada');

        // e as evidências adulteradas aparecem na trilha de rejeição da validação (auditável).
        $rejItems = array_column($sem['validation']['rejected'] ?? [], 'reason');
        $this->assertNotEmpty(array_filter($rejItems, fn ($r) => str_starts_with((string) $r, 'cross_source_')));
    }
}
