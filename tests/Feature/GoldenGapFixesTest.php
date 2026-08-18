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

    public function test_v4_detects_critical_rule_candidates_without_overfitting(): void
    {
        // detecta CATEGORIAS (autorização/limite), não o parâmetro específico → sem overfit.
        $m = new \ReflectionMethod(SourceDocSemanticAnalyzer::class, 'detectCriticalRuleCandidates');
        $m->setAccessible(true);
        $code = 'If !(cUsuario $ GetMV("MV_XUSRZ07")); MsgStop("Sem permissao"); Return .F.; EndIf'
            . ' nTeto := GetMV("MV_XPCDPED"); If nDesc > nTeto; Return .F.; EndIf';
        $hints = $m->invoke(new SourceDocSemanticAnalyzer($this->ai('{}')), ['sx6_params' => []], $code);
        $blob = implode(' | ', $hints);
        $this->assertStringContainsString('MV_XUSRZ07', $blob);
        $this->assertStringContainsString('AUTORIZAÇÃO', $blob);
        $this->assertStringContainsString('MV_XPCDPED', $blob);
        $this->assertStringContainsString('LIMITE/TETO', $blob);
        $this->assertStringContainsString('BLOQUEIO', $blob);
    }

    public function test_v5_candidate_code_extracts_bounded_snippets(): void
    {
        // extrai SÓ as linhas ao redor dos sinais (autorização/teto/bloqueio) — não o arquivo inteiro.
        $m = new \ReflectionMethod(SourceDocSemanticAnalyzer::class, 'criticalRuleCandidateCode');
        $m->setAccessible(true);
        $code = "Local x := 1\nLocal y := 2\n_cUserAuth := superGetMV(\"MV_XUSRZ07\", .f., \"\")\nIf !(cUsuario \$ _cUserAuth)\n  MsgStop(\"Sem permissao\")\n  Return .F.\nEndIf\nLocal z := 3\nProcessa()\nnTeto := getMV(\"MV_XPCDPED\")\n";
        $snip = $m->invoke(new SourceDocSemanticAnalyzer($this->ai('{}')), [], $code, 3500);
        $this->assertStringContainsString('MV_XUSRZ07', $snip);
        $this->assertStringContainsString('MV_XPCDPED', $snip);
        $this->assertStringContainsString('Return .F.', $snip);
        $this->assertStringNotContainsString('Local x := 1', $snip, 'linhas sem sinal não entram (bounded)');
        $this->assertStringNotContainsString('Local z := 3', $snip);
    }

    public function test_crp_confirms_uncovered_critical_rule_with_own_budget(): void
    {
        // regra de autorização candidata (MV_XUSRZ07) não coberta → CRP confirma e valida contra os fatos.
        $existing = ['status' => 'partial', 'usage' => ['actual_cost_usd' => 0.25],
            'block_status' => ['entendimento' => 'ok', 'regras' => 'truncated', 'deps_risco' => 'ok'],
            'regras_negocio' => [['id' => 'RN01', 'descricao' => 'Regra genérica.', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'A']]]]];
        $code = '_cAuth := superGetMV("MV_XUSRZ07",.f.,""); If !(cUsr $ _cAuth); MsgStop("x"); Return .F.; EndIf';
        $ai = $this->ai(fn ($u) => json_encode(['decisions' => [
            ['candidato' => 'MV_XUSRZ07', 'decision' => 'confirmed_rule', 'rule' => ['titulo' => 'Autorização por MV_XUSRZ07', 'descricao' => 'Só usuários listados em MV_XUSRZ07 podem aprovar.', 'condicao' => 'usuário não está em MV_XUSRZ07', 'efeito' => 'bloqueia a ação', 'operacoes_protegidas' => ['aprovar'], 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'A']]]],
        ]]));
        $r = (new SourceDocSemanticAnalyzer($ai))->criticalRulesPass($existing, $this->detBiz(), $code, []);
        $crp = $r['critical_rules_pass'];
        $this->assertTrue($crp['triggered']);
        $this->assertSame(1, $crp['confirmed'], 'regra crítica confirmada e validada contra os fatos');
        $titulos = array_map(fn ($x) => $x['titulo'] ?? '', $r['regras_negocio']);
        $this->assertContains('Autorização por MV_XUSRZ07', $titulos, 'regra material sobreviveu com evidência');
        $this->assertSame('per_semantic_step', $r['usage']['cost_model']);
        $this->assertLessThanOrEqual(0.30, $r['usage']['topup_cost_usd'], 'passo próprio ≤ US$ 0,30');
    }

    public function test_crp_noop_when_no_uncovered_candidate(): void
    {
        // sem candidato descoberto → CRP não dispara, custo 0.
        $existing = ['status' => 'completed', 'usage' => ['actual_cost_usd' => 0.05], 'regras_negocio' => []];
        $ai = $this->ai(json_encode(['decisions' => []]));
        $r = (new SourceDocSemanticAnalyzer($ai))->criticalRulesPass($existing, ['functions' => [], 'tables' => []], 'Local x := 1', []);
        $this->assertFalse($r['critical_rules_pass']['triggered']);
        $this->assertCount(0, $ai->prompts, 'não gasta chamada quando não há candidato');
    }

    public function test_v4_topup_uses_per_step_budget_and_labels_cost(): void
    {
        // top-up parte de orçamento FRESCO (costBase=0) e rotula custo por passo (não "por fonte").
        $existing = ['status' => 'partial', 'usage' => ['actual_cost_usd' => 0.26],
            'block_status' => ['entendimento' => 'ok', 'regras' => 'truncated', 'deps_risco' => 'ok'],
            'funcoes_trace' => ['requested' => [], 'completed' => [], 'missing' => []], 'funcoes' => [], 'regras_negocio' => []];
        $ai = $this->ai(fn ($u) => str_contains($u, 'regras_negocio') || str_contains($u, 'RECUPERAÇÃO')
            ? json_encode(['regras_negocio' => [['id' => 'RN01', 'descricao' => 'Atualiza ZZ0_STATUS.', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'ZZ0', 'field' => 'ZZ0_STATUS']]]], 'change_summary' => 'x'])
            : json_encode(['risco_alteracao' => ['resumo' => 'x', 'fatores' => []]]));
        $r = (new SourceDocSemanticAnalyzer($ai))->topUp($existing, $this->detBiz(), 'codigo', null, []);
        $u = $r['usage'];
        $this->assertSame('per_semantic_step', $u['cost_model']);
        $this->assertSame(0.26, $u['initial_cost_usd']);            // custo do initial preservado (informativo)
        $this->assertLessThanOrEqual(0.30, $u['topup_cost_usd']);   // o PASSO respeita o hard-limit sozinho
        $this->assertArrayHasKey('total_cost_usd', $u);             // total da fonte = soma dos passos
    }
}
