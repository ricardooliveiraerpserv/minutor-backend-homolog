<?php

namespace Tests\Feature;

use App\SourceCode\Analyzer\SourceDocAiProvider;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Bloco 4 + 4.1 — camada semântica: subordinação, gate, anti-alucinação, evidência/confiança,
 * e a arquitetura de CUSTO (compact facts, seleção de relevantes, estimativa+hard limit, max calls,
 * reuso por blob, cache por função, incremental por diff, coverage, output budget). DB-free; provider fake.
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
            'services.source_doc_ai.cache_enabled' => false, // determinístico por teste (evita vazamento entre testes)
            'services.source_doc_ai.max_relevant_functions' => 12,
            'services.source_doc_ai.max_calls' => 3,
            'services.source_doc_ai.max_output_tokens_per_call' => 2000,
            'services.source_doc_ai.hard_limit_usd' => 0.30,
            'services.source_doc_ai.inline_code_max_chars' => 8000,
            'services.source_doc_ai.prompt_version' => 2,
        ]);
    }

    private function det(): array
    {
        return [
            'source_type' => 'Fonte Protheus', 'language' => 'AdvPL', 'file' => ['filename' => 'FTENVNFE.PRW'],
            'functions' => [
                ['name' => 'FTENVNFE', 'type' => 'User Function', 'start_line' => 1, 'end_line' => 5, 'params' => [], 'returns' => ['Nil'], 'called_by' => [], 'calls_internal' => [], 'calls_user' => ['U_FTENVNFU'], 'tables' => [], 'accesses' => [], 'effects' => ['scoped_variable'], 'evidence' => ['line_start' => 1, 'line_end' => 5]],
                ['name' => 'FTENVNFU', 'type' => 'User Function', 'start_line' => 6, 'end_line' => 10, 'params' => ['cId', 'cEmail'], 'returns' => ['Nil'], 'called_by' => ['FTENVNFE'], 'calls_internal' => [], 'calls_user' => [], 'tables' => ['SPED050'], 'accesses' => ['UPDATE'], 'effects' => ['database_write'], 'evidence' => ['line_start' => 6, 'line_end' => 10]],
            ],
            'tables' => [['table' => 'SPED050', 'alias' => 'SPED050', 'access' => ['UPDATE'], 'functions' => ['FTENVNFU'], 'read_fields' => [], 'write_fields' => ['EMAIL', 'STATUSMAIL'], 'where_fields' => ['NFE_ID'], 'source' => ['sql']]],
            'queries' => [['operation' => 'UPDATE', 'table' => 'SPED050', 'executor' => 'TCSQLExec', 'function' => 'FTENVNFU', 'construction' => 'concatenation', 'write_fields' => ['EMAIL', 'STATUSMAIL'], 'where_fields' => ['NFE_ID'], 'has_where' => true, 'risk_flags' => ['dynamic_sql_by_concatenation']]],
            'user_calls' => ['U_FTENVNFU'], 'external_integrations' => [], 'dependencies' => [], 'effects' => [], 'technical_flow' => [], 'security_findings' => [],
        ];
    }

    private function ai(bool $configured, $responder): SourceDocAiProvider
    {
        return new class($configured, $responder) implements SourceDocAiProvider {
            public array $calls = [];
            public array $opts = [];
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
                $this->opts[] = $opts;
                if ($this->r instanceof \Throwable) {
                    throw $this->r;
                }
                $res = is_callable($this->r) ? ($this->r)($user, $i) : $this->r;
                // responder pode devolver string OU ['text'=>..,'stop'=>..] p/ simular truncamento.
                if (is_array($res)) {
                    return ['text' => (string) ($res['text'] ?? ''), 'usage' => ['input_tokens' => 120, 'output_tokens' => 60], 'stop' => $res['stop'] ?? 'end_turn'];
                }
                return ['text' => (string) $res, 'usage' => ['input_tokens' => 120, 'output_tokens' => 60], 'stop' => 'end_turn'];
            }
        };
    }

    private function go(SourceDocAiProvider $ai, string $code = 'codigo', ?array $diff = null, array $ctx = [], ?array $det = null): array
    {
        return (new SourceDocSemanticAnalyzer($ai))->analyze($det ?? $this->det(), $code, $diff, $ctx);
    }

    private function validJson(array $over = []): string
    {
        return json_encode(array_replace([
            'entendimento_funcional' => [
                'uma_frase' => ['texto' => 'Reenvia o XML da NF-e e grava o status de envio.', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]],
                'objetivo' => 'Reenvia o XML da NF-e por e-mail e registra o status no SPED050.',
                'quando_usado' => 'No reenvio de NF-e.',
                'processo_modulo' => ['processo' => 'Emissão fiscal', 'modulo' => 'Fiscal', 'confidence' => 'medium', 'evidence' => [['type' => 'table', 'table' => 'SPED050']]],
                'entradas_principais' => [['tipo' => 'parametro', 'nome' => 'cId', 'descricao' => 'Id da NF-e', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]]],
                'saidas_principais' => [['tipo' => 'atualizacao', 'nome' => 'SPED050.STATUSMAIL', 'descricao' => 'Status do envio', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'STATUSMAIL']]]],
                'o_que_faz' => [['passo' => 'Recebe o id', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]]],
            ],
            'objetivo' => 'Reenvia o XML da NF-e por e-mail.',
            'fluxo' => ['Recebe parâmetros', 'Atualiza SPED050'],
            'funcoes' => [['name' => 'FTENVNFU', 'finalidade' => 'Grava e-mail e status.'], ['name' => 'FTENVNFE', 'finalidade' => 'Entrada.']],
            'regras_negocio' => [['id' => 'RN01', 'descricao' => 'STATUSMAIL é atualizado.', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'STATUSMAIL'], ['type' => 'function', 'name' => 'FTENVNFU']]]],
            'entradas' => ['cId', 'cEmail'], 'saidas' => ['UPDATE SPED050'],
            'pontos_atencao' => [['interpretation' => 'SQL por concatenação em FTENVNFU.', 'severity' => 'média', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]]],
            'change_summary' => 'Passou a atualizar STATUSMAIL.',
        ], $over));
    }


    private function entBlock(): string
    {
        return json_encode(['entendimento_funcional' => [
            'uma_frase' => ['texto' => 'Faz X.', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]],
            'objetivo' => 'Faz X.', 'quando_usado' => 'No reenvio.', 'o_que_faz' => [],
        ], 'fluxo' => ['p1']]);
    }
    private function rulesBlock(): string { return json_encode(['regras_negocio' => [], 'change_summary' => 'x']); }
    private function funcoesBlock(): string { return json_encode(['funcoes' => [['name' => 'FTENVNFU', 'finalidade' => 'grava']]]); }

    // ── base (Bloco 4) ──
    public function test_no_provider_is_pending(): void
    {
        $this->assertSame('pending', $this->go($this->ai(false, $this->validJson()))['status']);
    }

    public function test_provider_failure_is_failed(): void
    {
        $this->assertSame('failed', $this->go($this->ai(true, new \RuntimeException('down')))['status']);
    }

    public function test_valid_response(): void
    {
        $r = $this->go($this->ai(true, $this->validJson()));
        $this->assertSame('completed', $r['status']);
        $this->assertContains('FTENVNFU', array_column($r['funcoes'], 'name'));
        $this->assertSame('RN01', $r['regras_negocio'][0]['id']);
        $this->assertStringContainsString('concatenação', $r['pontos_atencao'][0]);
    }

    public function test_invented_function_rejected(): void
    {
        $r = $this->go($this->ai(true, $this->validJson(['funcoes' => [['name' => 'U_NAOEXISTE', 'finalidade' => 'x'], ['name' => 'FTENVNFU', 'finalidade' => 'ok']]])));
        $this->assertNotContains('U_NAOEXISTE', array_column($r['funcoes'], 'name'));
        $this->assertGreaterThan(0, $r['validation']['rejected_count']);
    }

    public function test_invented_table_rejected(): void
    {
        $r = $this->go($this->ai(true, $this->validJson(['funcoes' => [], 'regras_negocio' => [], 'pontos_atencao' => [], 'table_purposes' => [['alias' => 'SB1', 'finalidade' => 'x'], ['alias' => 'SPED050', 'finalidade' => 'ok']]])));
        $this->assertNotContains('SB1', array_column($r['tabelas'], 'alias'));
    }

    public function test_invented_field_rejected(): void
    {
        $r = $this->go($this->ai(true, $this->validJson(['regras_negocio' => [['id' => 'RN01', 'descricao' => 'campo fantasma', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'B1_FANTASMA']]]]])));
        $this->assertEmpty($r['regras_negocio']);
        $this->assertGreaterThan(0, $r['validation']['rejected_count']);
    }

    public function test_rule_without_evidence_rejected(): void
    {
        $r = $this->go($this->ai(true, $this->validJson(['regras_negocio' => [['id' => 'RN01', 'descricao' => 'sem prova', 'confidence' => 'high', 'evidence' => []]]])));
        $this->assertEmpty($r['regras_negocio']);
    }

    public function test_production_blocked(): void
    {
        config(['services.source_doc_ai.environment' => 'production']);
        $ai = $this->ai(true, $this->validJson());
        $this->assertSame('pending', $this->go($ai)['status']);
        $this->assertEmpty($ai->calls);
    }

    // ── Bloco 4.1 — custo/arquitetura ──
    public function test_secret_masked_in_payload(): void
    {
        $ai = $this->ai(true, $this->validJson());
        $this->go($ai, "cToken := \"[REDACTED_SECRET]\"\nDbSelectArea('SPED050')");
        $sent = implode("\n", $ai->calls);
        $this->assertStringContainsString('[REDACTED_SECRET]', $sent);
        $this->assertStringNotContainsString('AKIA', $sent);
    }

    public function test_structural_change_false_zero_calls(): void
    {
        $ai = $this->ai(true, $this->validJson());
        $r = $this->go($ai, 'codigo', ['diff_stats' => ['change_type' => 'modified', 'structural_change' => false]]);
        $this->assertSame('skipped_no_structural_change', $r['status']);
        $this->assertEmpty($ai->calls, '0 chamadas quando não há mudança estrutural');
    }

    public function test_initial_is_completed_not_partial(): void
    {
        // fonte "grande" (código acima do inline) NÃO deve virar partial só por não descrever tudo
        config(['services.source_doc_ai.inline_code_max_chars' => 30]);
        $responder = fn ($user, $i) => $this->validJson();
        $r = $this->go($this->ai(true, $responder), str_repeat("linha\n", 40));
        $this->assertSame('completed', $r['status']);
        $this->assertSame('initial_blocks_v2', $r['strategy']);
        $this->assertArrayHasKey('relevant_functions_total', $r['semantic_coverage']);
    }

    public function test_max_calls_respected(): void
    {
        config(['services.source_doc_ai.inline_code_max_chars' => 30, 'services.source_doc_ai.max_calls' => 1]);
        $ai = $this->ai(true, fn ($u, $i) => $this->validJson());
        $this->go($ai, str_repeat("linha\n", 40));
        $this->assertLessThanOrEqual(1, count($ai->calls), 'max_calls=1 ⇒ só a chamada global');
    }

    public function test_output_budget_passed_to_provider(): void
    {
        config(['services.source_doc_ai.max_output_tokens_entendimento' => 1600]);
        $ai = $this->ai(true, $this->validJson());
        $this->go($ai);
        $this->assertSame(1600, $ai->opts[0]['max_tokens'] ?? null, 'a 1ª chamada (Entendimento) usa seu output budget');
    }

    public function test_hard_limit_skips_before_calling(): void
    {
        config(['services.source_doc_ai.hard_limit_usd' => 0.0000001]); // força estouro
        $ai = $this->ai(true, $this->validJson());
        $r = $this->go($ai);
        $this->assertSame('skipped_cost_limit', $r['status']);
        $this->assertEmpty($ai->calls, 'não chama o provider quando a estimativa passa do hard limit');
        $this->assertGreaterThan(0, $r['usage']['estimated_before_usd']);
    }

    public function test_blob_reuse_zero_second_call(): void
    {
        config(['services.source_doc_ai.cache_enabled' => true]);
        Cache::flush();
        $ai = $this->ai(true, $this->validJson());
        $sem = new SourceDocSemanticAnalyzer($ai);
        $sem->analyze($this->det(), 'mesmo-codigo', null, ['blob_sha' => 'BLOB1']);
        $r2 = $sem->analyze($this->det(), 'mesmo-codigo', null, ['blob_sha' => 'BLOB1']);
        $this->assertSame('reuse_blob', $r2['strategy']);
        $this->assertSame(3, count($ai->calls), '2ª análise do mesmo blob não chama a IA (1ª = 3 blocos)');
        Cache::flush();
    }

    public function test_cache_invalidated_by_prompt_version(): void
    {
        config(['services.source_doc_ai.cache_enabled' => true]);
        Cache::flush();
        $ai = $this->ai(true, $this->validJson());
        $sem = new SourceDocSemanticAnalyzer($ai);
        $sem->analyze($this->det(), 'codigo', null, ['blob_sha' => 'B']);
        config(['services.source_doc_ai.prompt_version' => 99]); // muda a chave de cache
        $sem->analyze($this->det(), 'codigo', null, ['blob_sha' => 'B']);
        $this->assertSame(6, count($ai->calls), 'mudança de prompt_version invalida o cache (2 análises × 3 blocos)');
        Cache::flush();
    }

    public function test_incremental_merge_add_update_remove_keep(): void
    {
        $prev = [
            'schema_version' => 1, 'status' => 'completed', 'objetivo' => 'Objetivo anterior.',
            'funcoes' => [['name' => 'FTENVNFE', 'finalidade' => 'antiga E'], ['name' => 'FTENVNFU', 'finalidade' => 'antiga U']],
            'regras_negocio' => [['id' => 'RN01', 'descricao' => 'regra velha', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]]],
            'business_rules' => [['id' => 'RN01', 'descricao' => 'regra velha', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]]],
            'pontos_atencao' => [], 'entradas' => [], 'saidas' => [], 'tabelas' => [],
        ];
        $diff = ['diff_stats' => ['change_type' => 'modified', 'structural_change' => true], 'structural' => ['functions' => ['changed' => [['function' => 'FTENVNFU']]]]];
        $delta = json_encode([
            'change_summary' => 'FTENVNFU passou a gravar STATUSMAIL.',
            'updated_functions' => [['name' => 'FTENVNFU', 'finalidade' => 'nova U (grava STATUSMAIL)']],
            'rules_add' => [['id' => 'RN02', 'descricao' => 'novo comportamento', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'STATUSMAIL']]]],
            'rules_remove' => ['RN01'],
        ]);
        $ai = $this->ai(true, $delta);
        $r = (new SourceDocSemanticAnalyzer($ai))->analyze($this->det(), "linha\nFTENVNFU code", $diff, ['previous_semantic' => $prev]);
        $this->assertSame('incremental_diff', $r['strategy']);
        // KEEP: FTENVNFE preservada
        $fe = array_values(array_filter($r['funcoes'], fn ($f) => $f['name'] === 'FTENVNFE'))[0] ?? null;
        $this->assertSame('antiga E', $fe['finalidade']);
        // UPDATE: FTENVNFU atualizada
        $fu = array_values(array_filter($r['funcoes'], fn ($f) => $f['name'] === 'FTENVNFU'))[0] ?? null;
        $this->assertStringContainsString('STATUSMAIL', $fu['finalidade']);
        // REMOVE RN01, ADD RN02
        $ids = array_column($r['regras_negocio'], 'id');
        $this->assertNotContains('RN01', $ids);
        $this->assertContains('RN02', $ids);
        $this->assertSame(1, count($ai->calls), 'incremental usa 1 chamada');
    }

    // ── §F — truncamento / completude (nunca completed vazio) ──
    /** (F1) stop_reason=max_tokens na global → partial, nunca completed. */
    public function test_global_truncated_is_partial(): void
    {
        $ai = $this->ai(true, fn ($u, $i) => ['text' => $this->validJson(), 'stop' => 'max_tokens']);
        $r = $this->go($ai);
        $this->assertSame('partial', $r['status']);
        $this->assertSame('entendimento_truncated', $r['partial_reason']);
    }

    /** (F2) JSON inválido → partial (invalid_json), nunca completed. */
    public function test_invalid_json_is_partial(): void
    {
        $r = $this->go($this->ai(true, 'isto não é json {quebrado'));
        $this->assertSame('partial', $r['status']);
        $this->assertSame('entendimento_invalid_json', $r['partial_reason']);
    }

    /** (F3) JSON válido mas semanticamente vazio → nunca completed. */
    public function test_empty_semantic_not_completed(): void
    {
        $r = $this->go($this->ai(true, '{}'));
        $this->assertNotSame('completed', $r['status']);
        $this->assertSame('entendimento_invalid_json', $r['partial_reason']);
    }

    /** (F4) global válida sem funções → completed (funções vêm do aprofundamento). */
    public function test_global_valid_without_functions_is_ok(): void
    {
        // ent + regras válidos + funções válidas ⇒ completed (blocos independentes)
        config(['services.source_doc_ai.inline_code_max_chars' => 30]);
        $ai = $this->ai(true, fn ($u, $i) => $i === 0 ? $this->entBlock() : ($i === 1 ? $this->rulesBlock() : $this->funcoesBlock()));
        $r = $this->go($ai, str_repeat("linha
", 40));
        $this->assertSame('completed', $r['status']);
        $this->assertSame('Faz X.', $r['entendimento_funcional']['objetivo']);
    }

    /** (F5) aprofundamento falha (truncado) mas global válida → partial + global preservada. */
    public function test_deepening_partial_keeps_global(): void
    {
        // Entendimento válido; aprofundamento (funções) trunca ⇒ partial preservando o Entendimento.
        config(['services.source_doc_ai.inline_code_max_chars' => 30]);
        $ai = $this->ai(true, fn ($u, $i) => $i === 0 ? $this->entBlock() : ($i === 1 ? $this->rulesBlock() : ['text' => $this->funcoesBlock(), 'stop' => 'max_tokens']));
        $r = $this->go($ai, str_repeat("linha
", 40));
        $this->assertSame('partial', $r['status']);
        $this->assertSame('functions_incomplete', $r['partial_reason']);
        $this->assertSame('Faz X.', $r['entendimento_funcional']['objetivo'], 'entendimento preservado');
    }

    /** (F6) global + aprofundamento válidos → completed. */
    public function test_global_and_deepening_valid_completed(): void
    {
        config(['services.source_doc_ai.inline_code_max_chars' => 30]);
        $ai = $this->ai(true, fn ($u, $i) => $i === 0 ? $this->entBlock() : ($i === 1 ? $this->rulesBlock() : $this->funcoesBlock()));
        $r = $this->go($ai, str_repeat("linha
", 40));
        $this->assertSame('completed', $r['status']);
        $this->assertContains('FTENVNFU', array_column($r['funcoes'], 'name'));
    }

    /** (F7) nenhum conteúdo semântico válido → não completed (determinístico segue intacto no pipeline). */
    public function test_no_valid_semantic_is_partial(): void
    {
        $r = $this->go($this->ai(true, ''));
        $this->assertNotSame('completed', $r['status']);
    }

    public function test_coverage_and_usage_present(): void
    {
        $r = $this->go($this->ai(true, $this->validJson()));
        foreach (['relevant_functions_total', 'relevant_functions_analyzed', 'relevant_functions_cached', 'relevant_functions_skipped'] as $k) {
            $this->assertArrayHasKey($k, $r['semantic_coverage']);
        }
        foreach (['input_tokens', 'output_tokens', 'calls', 'estimated_before_usd', 'actual_cost_usd', 'cache_hits', 'cache_misses', 'duration_ms'] as $k) {
            $this->assertArrayHasKey($k, $r['usage']);
        }
    }
}
