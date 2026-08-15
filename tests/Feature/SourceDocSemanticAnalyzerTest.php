<?php

namespace Tests\Feature;

use App\SourceCode\Analyzer\SourceDocAiProvider;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use Tests\TestCase;

/**
 * Bloco 4 — camada semântica subordinada ao determinístico. Testa gate homolog/prod, anti-alucinação
 * (função/tabela/campo inventados), evidência obrigatória, confiança, diff resumido, chunking/partial,
 * fallback, sanitização e observabilidade. DB-free; provider fake (nunca chama a Anthropic real).
 */
class SourceDocSemanticAnalyzerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.source_doc_ai.enabled' => true,
            'services.source_doc_ai.environment' => 'homolog',
            'services.source_doc_ai.allowed_environments' => ['homolog'],
            'services.source_doc_ai.max_chars' => 40000,
        ]);
    }

    private function det(): array
    {
        return [
            'source_type' => 'Fonte Protheus', 'language' => 'AdvPL',
            'functions' => [
                ['name' => 'FTENVNFE', 'type' => 'User Function', 'start_line' => 1, 'end_line' => 5, 'params' => [], 'returns' => ['Nil'], 'called_by' => [], 'calls_internal' => [], 'calls_user' => ['U_FTENVNFU'], 'tables' => [], 'accesses' => [], 'effects' => ['scoped_variable']],
                ['name' => 'FTENVNFU', 'type' => 'User Function', 'start_line' => 6, 'end_line' => 10, 'params' => ['cId', 'cEmail'], 'returns' => ['Nil'], 'called_by' => ['FTENVNFE'], 'calls_internal' => [], 'calls_user' => [], 'tables' => ['SPED050'], 'accesses' => ['UPDATE'], 'effects' => ['database_write']],
            ],
            'tables' => [['table' => 'SPED050', 'alias' => 'SPED050', 'access' => ['UPDATE'], 'functions' => ['FTENVNFU'], 'read_fields' => [], 'write_fields' => ['EMAIL', 'STATUSMAIL'], 'where_fields' => ['NFE_ID'], 'source' => ['sql']]],
            'queries' => [['operation' => 'UPDATE', 'table' => 'SPED050', 'executor' => 'TCSQLExec', 'function' => 'FTENVNFU', 'construction' => 'concatenation', 'write_fields' => ['EMAIL', 'STATUSMAIL'], 'where_fields' => ['NFE_ID'], 'has_where' => true, 'risk_flags' => ['dynamic_sql_by_concatenation']]],
            'user_calls' => ['U_FTENVNFU'],
            'external_integrations' => [], 'dependencies' => [], 'effects' => [], 'technical_flow' => [], 'security_findings' => [],
        ];
    }

    private function ai(bool $configured, $responder): SourceDocAiProvider
    {
        return new class($configured, $responder) implements SourceDocAiProvider {
            public array $calls = [];
            private bool $cfg;
            private $r;
            public function __construct(bool $cfg, $r)
            {
                $this->cfg = $cfg;
                $this->r = $r;
            }
            public function isConfigured(): bool
            {
                return $this->cfg;
            }
            public function name(): string
            {
                return 'fake';
            }
            public function model(): string
            {
                return 'fake-1';
            }
            public function complete(string $system, string $user, array $opts = []): array
            {
                $i = count($this->calls);
                $this->calls[] = $user;
                $r = $this->r;
                if ($r instanceof \Throwable) {
                    throw $r;
                }
                $text = is_callable($r) ? $r($user, $i) : (string) $r;
                return ['text' => $text, 'usage' => ['input_tokens' => 120, 'output_tokens' => 60], 'stop' => 'end_turn'];
            }
        };
    }

    private function analyze(SourceDocAiProvider $ai, string $code = 'codigo', ?array $diff = null, ?array $det = null): array
    {
        return (new SourceDocSemanticAnalyzer($ai))->analyze($det ?? $this->det(), $code, $diff);
    }

    private function validJson(array $over = []): string
    {
        return json_encode(array_replace([
            'objetivo' => 'Reenvia o XML da NF-e por e-mail.',
            'fluxo' => ['Recebe parâmetros', 'Atualiza SPED050'],
            'funcoes' => [['name' => 'FTENVNFU', 'finalidade' => 'Grava o e-mail e status.']],
            'table_purposes' => [['alias' => 'SPED050', 'finalidade' => 'Controle de envio de NF-e.']],
            'regras_negocio' => [['id' => 'RN01', 'descricao' => 'STATUSMAIL é atualizado ao reenviar.', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'STATUSMAIL'], ['type' => 'function', 'name' => 'FTENVNFU']]]],
            'entradas' => ['cId', 'cEmail'],
            'saidas' => ['UPDATE SPED050'],
            'pontos_atencao' => [['interpretation' => 'SQL construído por concatenação em FTENVNFU.', 'severity' => 'média', 'recommendation' => 'avaliar parametrização', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]]],
            'change_summary' => 'Passou a atualizar STATUSMAIL.',
        ], $over));
    }

    /** 1) sem provider → pending. */
    public function test_no_provider_is_pending(): void
    {
        $r = $this->analyze($this->ai(false, $this->validJson()));
        $this->assertSame('pending', $r['status']);
    }

    /** 2) provider indisponível → failed, determinístico preservado. */
    public function test_provider_failure_is_failed(): void
    {
        $ai = $this->ai(true, new \RuntimeException('down'));
        $r = $this->analyze($ai);
        $this->assertSame('failed', $r['status']);
    }

    /** 3) resposta válida → completed com regras/funcoes. */
    public function test_valid_response(): void
    {
        $r = $this->analyze($this->ai(true, $this->validJson()));
        $this->assertSame('completed', $r['status']);
        $this->assertSame('FTENVNFU', $r['funcoes'][0]['name']);
        $this->assertSame('RN01', $r['regras_negocio'][0]['id']);
        $this->assertStringContainsString('concatenação', $r['pontos_atencao'][0]);
    }

    /** 4) função inventada → rejeitada. */
    public function test_invented_function_rejected(): void
    {
        $r = $this->analyze($this->ai(true, $this->validJson(['funcoes' => [['name' => 'U_NAOEXISTE', 'finalidade' => 'x'], ['name' => 'FTENVNFU', 'finalidade' => 'ok']]])));
        $names = array_column($r['funcoes'], 'name');
        $this->assertContains('FTENVNFU', $names);
        $this->assertNotContains('U_NAOEXISTE', $names);
        $this->assertGreaterThan(0, $r['validation']['rejected_count']);
    }

    /** 5) tabela inventada → rejeitada. */
    public function test_invented_table_rejected(): void
    {
        $r = $this->analyze($this->ai(true, $this->validJson(['table_purposes' => [['alias' => 'SB1', 'finalidade' => 'x'], ['alias' => 'SPED050', 'finalidade' => 'ok']]])));
        $aliases = array_column($r['tabelas'], 'alias');
        $this->assertContains('SPED050', $aliases);
        $this->assertNotContains('SB1', $aliases);
    }

    /** 6) campo inventado na evidência → regra perde evidência e é rejeitada. */
    public function test_invented_field_rejected(): void
    {
        $r = $this->analyze($this->ai(true, $this->validJson(['regras_negocio' => [['id' => 'RN01', 'descricao' => 'campo fantasma', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'B1_FANTASMA']]]]])));
        $this->assertEmpty($r['regras_negocio']);
        $this->assertGreaterThan(0, $r['validation']['rejected_count']);
    }

    /** 7) regra sem evidência → rejeitada. */
    public function test_rule_without_evidence_rejected(): void
    {
        $r = $this->analyze($this->ai(true, $this->validJson(['regras_negocio' => [['id' => 'RN01', 'descricao' => 'sem prova', 'confidence' => 'high', 'evidence' => []]]])));
        $this->assertEmpty($r['regras_negocio']);
    }

    /** 8) diff resumido corretamente (estrutural). */
    public function test_diff_summary_structural(): void
    {
        $diff = ['diff_stats' => ['change_type' => 'modified', 'structural_change' => true]];
        $r = $this->analyze($this->ai(true, $this->validJson()), 'codigo', $diff);
        $this->assertSame('Passou a atualizar STATUSMAIL.', $r['resumo_alteracao']);
    }

    /** 9) structural_change=false → resumo não estrutural (não inventa). */
    public function test_diff_non_structural(): void
    {
        $diff = ['diff_stats' => ['change_type' => 'modified', 'structural_change' => false]];
        $r = $this->analyze($this->ai(true, $this->validJson(['change_summary' => 'IA tentou inventar algo grande'])), 'codigo', $diff);
        $this->assertStringContainsString('Não foram identificadas alterações estruturais', $r['resumo_alteracao']);
    }

    /** 10) initial → documentação inicial. */
    public function test_diff_initial(): void
    {
        $diff = ['diff_stats' => ['change_type' => 'initial', 'structural_change' => true]];
        $r = $this->analyze($this->ai(true, $this->validJson()), 'codigo', $diff);
        $this->assertStringContainsString('Documentação inicial', $r['resumo_alteracao']);
    }

    /** 11) segredo já mascarado é o que chega ao provider (analyzer não vaza). */
    public function test_secret_is_masked_in_payload(): void
    {
        $ai = $this->ai(true, $this->validJson());
        $this->analyze($ai, "cToken := \"[REDACTED_SECRET]\"\nDbSelectArea('SPED050')");
        $sent = implode("\n", $ai->calls);
        $this->assertStringContainsString('[REDACTED_SECRET]', $sent);
        $this->assertStringNotContainsString('AKIA', $sent);
    }

    /** 12) chunking em fonte grande → status partial + múltiplos chunks. */
    public function test_chunking_partial(): void
    {
        config(['services.source_doc_ai.max_chars' => 20]);
        $code = str_repeat("linha de codigo aqui\n", 10); // > 20 chars → chunked
        $responder = fn ($user, $i) => json_encode(['funcoes' => [['name' => 'FTENVNFU', 'finalidade' => 'ok']], 'objetivo' => 'x']);
        $r = $this->analyze($this->ai(true, $responder), $code);
        $this->assertSame('partial', $r['status']);
        $this->assertGreaterThanOrEqual(2, $r['chunking']['chunks']);
    }

    /** 13) chunk que falha é contabilizado (partial, failed>0). */
    public function test_chunking_failed_counted(): void
    {
        config(['services.source_doc_ai.max_chars' => 20]);
        $code = str_repeat("linha de codigo aqui\n", 10);
        $responder = function ($user, $i) {
            if ($i === 0) {
                throw new \RuntimeException('chunk 0 down');
            }
            return json_encode(['funcoes' => [], 'objetivo' => 'x']);
        };
        $r = $this->analyze($this->ai(true, $responder), $code);
        $this->assertSame('partial', $r['status']);
        $this->assertGreaterThan(0, $r['chunking']['failed']);
    }

    /** 14) idempotente: reprocessar não acumula rejeições (validator zera por chamada). */
    public function test_reprocess_idempotent_counters(): void
    {
        $ai = $this->ai(true, $this->validJson(['funcoes' => [['name' => 'U_NAOEXISTE', 'finalidade' => 'x']]]));
        $sem = new SourceDocSemanticAnalyzer($ai);
        $r1 = $sem->analyze($this->det(), 'codigo', null);
        $r2 = $sem->analyze($this->det(), 'codigo', null);
        $this->assertSame($r1['validation']['rejected_count'], $r2['validation']['rejected_count']);
    }

    /** 15) produção bloqueada (ambiente não autorizado) → pending, provider NÃO chamado. */
    public function test_production_blocked(): void
    {
        config(['services.source_doc_ai.environment' => 'production', 'services.source_doc_ai.allowed_environments' => ['homolog']]);
        $ai = $this->ai(true, $this->validJson());
        $r = $this->analyze($ai);
        $this->assertSame('pending', $r['status']);
        $this->assertEmpty($ai->calls, 'não deve chamar a IA em ambiente bloqueado');
    }

    /** 16) homolog permitido → completed. */
    public function test_homolog_allowed(): void
    {
        config(['services.source_doc_ai.environment' => 'homolog', 'services.source_doc_ai.allowed_environments' => ['homolog']]);
        $r = $this->analyze($this->ai(true, $this->validJson()));
        $this->assertSame('completed', $r['status']);
        $this->assertArrayHasKey('estimated_cost_usd', $r['usage']);
        $this->assertGreaterThan(0, $r['usage']['input_tokens']);
    }
}
