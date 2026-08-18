<?php

namespace Tests\Feature;

use App\SourceCode\Analyzer\SourceDocAiProvider;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use Tests\TestCase;

/**
 * Golden Set — correções dos 2 gaps reprovados no piloto:
 *  GAP 1: rota simple, com operação de negócio, não pode entregar regras=0 → cai p/ multi-bloco.
 *  GAP 2: bloco crítico que ficaria zerado é recuperado (ou marcado missing_cost_budget, nunca silencioso).
 */
class GoldenGapFixesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.source_doc_ai.enabled' => true,
            'services.source_doc_ai.environment' => 'homolog',
            'services.source_doc_ai.allowed_environments' => ['homolog'],
            'services.source_doc_ai.cache_enabled' => false,
            'services.source_doc_ai.hard_limit_usd' => 0.30,
            'services.source_doc_ai.simple_route_enabled' => true,
            'services.source_doc_ai.simple_max_functions' => 3,
        ]);
    }

    private function ai($responder): SourceDocAiProvider
    {
        return new class($responder) implements SourceDocAiProvider {
            public array $prompts = [];
            public function __construct(private $r) {}
            public function isConfigured(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function model(): string { return 'fake-1'; }
            public function complete(string $system, string $user, array $opts = []): array
            {
                $this->prompts[] = $user;
                $t = is_callable($this->r) ? ($this->r)($user, count($this->prompts) - 1) : $this->r;
                if (is_array($t)) { // suporta simulação de truncamento: ['text'=>..,'stop'=>'max_tokens']
                    return ['text' => (string) ($t['text'] ?? ''), 'usage' => ['input_tokens' => 120, 'output_tokens' => 60], 'stop' => $t['stop'] ?? 'end_turn'];
                }
                return ['text' => (string) $t, 'usage' => ['input_tokens' => 120, 'output_tokens' => 60], 'stop' => 'end_turn'];
            }
        };
    }

    /** det com operação de negócio (query UPDATE + write_fields). */
    private function detBiz(): array
    {
        return [
            'source_type' => 'x', 'language' => 'AdvPL', 'file' => ['filename' => 'A.prw'],
            'functions' => [['name' => 'A', 'type' => 'User Function', 'start_line' => 1, 'end_line' => 6, 'calls_user' => [], 'tables' => ['ZZ0'], 'evidence' => ['line_start' => 1, 'line_end' => 6]]],
            'tables' => [['table' => 'ZZ0', 'alias' => 'ZZ0', 'access' => ['UPDATE'], 'functions' => ['A'], 'write_fields' => ['ZZ0_STATUS'], 'read_fields' => []]],
            'queries' => [['operation' => 'UPDATE', 'table' => 'ZZ0', 'function' => 'A', 'write_fields' => ['ZZ0_STATUS'], 'has_where' => true]],
            'user_calls' => [], 'dependencies' => [], 'security_findings' => [],
        ];
    }

    private function entJson(array $over = []): string
    {
        return json_encode(array_replace([
            'entendimento_funcional' => ['uma_frase' => ['texto' => 'Faz X.', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'A']]], 'objetivo' => 'Atualiza status.', 'quando_usado' => 'x', 'o_que_faz' => []],
            'fluxo' => ['p'],
        ], $over));
    }

    public function test_gap1_simple_no_rules_with_business_ops_falls_back(): void
    {
        // rota simple responde SEM regras, embora haja UPDATE em ZZ0 → deve cair p/ multi-bloco.
        $ai = $this->ai(function ($user) {
            if (str_contains($user, 'Fonte pequeno')) { // prompt da rota simple
                return json_encode(['entendimento_funcional' => ['uma_frase' => ['texto' => 'Faz X.', 'confidence' => 'high', 'evidence' => []], 'objetivo' => 'x', 'o_que_faz' => []], 'funcoes' => [], 'regras_negocio' => []]);
            }
            if (str_contains($user, 'regras_negocio[')) {
                return json_encode(['regras_negocio' => [['id' => 'RN01', 'descricao' => 'Atualiza ZZ0_STATUS quando A executa.', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'ZZ0', 'field' => 'ZZ0_STATUS']]]], 'change_summary' => 'x']);
            }
            if (str_contains($user, 'FUNÇÕES RELEVANTES')) {
                return json_encode(['funcoes' => [['name' => 'A', 'finalidade' => 'Atualiza status.', 'confidence' => 'high', 'evidence' => []]]]);
            }
            if (str_contains($user, 'entendimento_funcional')) { return $this->entJson(); }
            return json_encode(['risco_alteracao' => ['resumo' => 'x', 'fatores' => []], 'dependencias_criticas' => [], 'pontos_atencao' => []]);
        });
        $r = (new SourceDocSemanticAnalyzer($ai))->analyze($this->detBiz(), 'codigo', null, []);
        $this->assertSame('initial_blocks_v3', $r['strategy'], 'simple sem regras + operação de negócio → fallback multi-bloco');
        $this->assertNotEmpty($r['regras_negocio'], 'multi-bloco recuperou a regra material');
    }

    public function test_gap1_simple_keeps_single_call_when_complete(): void
    {
        // rota simple COMPLETA (com regra) não deve cair p/ multi-bloco (economia preservada).
        $ai = $this->ai(function ($user) {
            return json_encode(['entendimento_funcional' => ['uma_frase' => ['texto' => 'Faz X.', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'A']]], 'objetivo' => 'x', 'quando_usado' => 'x', 'o_que_faz' => []],
                'funcoes' => [['name' => 'A', 'finalidade' => 'y', 'confidence' => 'high', 'evidence' => []]],
                'regras_negocio' => [['id' => 'RN01', 'descricao' => 'Atualiza ZZ0_STATUS.', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'ZZ0', 'field' => 'ZZ0_STATUS']]]],
                'change_summary' => 'x']);
        });
        $r = (new SourceDocSemanticAnalyzer($ai))->analyze($this->detBiz(), 'codigo', null, []);
        $this->assertSame('simple_single_call', $r['strategy'], '1 chamada basta quando completa');
        $this->assertCount(1, $ai->prompts, 'economia: 1 chamada só');
    }

    public function test_gap1_v3_truncation_triggers_fallback_regardless_of_business_ops(): void
    {
        // v3: truncou (dimensão crítica) ⇒ cai p/ multi-bloco MESMO sem sinal determinístico de operação.
        $det = $this->detBiz();
        $det['tables'] = [['table' => 'ZZ0', 'alias' => 'ZZ0', 'access' => ['READ'], 'functions' => ['A'], 'read_fields' => ['ZZ0_COD']]];
        $det['queries'] = []; // hasBusinessOps=false, mas truncamento deve bastar
        $this->assertFalse($this->analyzerHasBusinessOps($det));
        $ai = $this->ai(function ($user) {
            if (str_contains($user, 'Fonte pequeno')) {
                return ['text' => '{"entendimento_funcional":{"uma_frase":{"texto":"x","confidence":"low","evidence":[]},"objetivo":"x","o_que_faz":[]}', 'stop' => 'max_tokens']; // truncado
            }
            if (str_contains($user, 'regras_negocio[')) { return json_encode(['regras_negocio' => [], 'change_summary' => 'x']); }
            if (str_contains($user, 'FUNÇÕES RELEVANTES')) { return json_encode(['funcoes' => [['name' => 'A', 'finalidade' => 'y', 'confidence' => 'low', 'evidence' => []]]]); }
            if (str_contains($user, 'entendimento_funcional')) { return $this->entJson(); }
            return json_encode(['risco_alteracao' => ['resumo' => 'x', 'fatores' => []], 'dependencias_criticas' => [], 'pontos_atencao' => []]);
        });
        $r = (new SourceDocSemanticAnalyzer($ai))->analyze($det, 'codigo', null, []);
        $this->assertSame('initial_blocks_v3', $r['strategy'], 'truncamento na rota simple ⇒ fallback independente de hasBusinessOps');
    }

    private function analyzerHasBusinessOps(array $det): bool
    {
        $m = new \ReflectionMethod(SourceDocSemanticAnalyzer::class, 'hasBusinessOps');
        $m->setAccessible(true);
        return $m->invoke(new SourceDocSemanticAnalyzer($this->ai('{}')), $det);
    }
}
