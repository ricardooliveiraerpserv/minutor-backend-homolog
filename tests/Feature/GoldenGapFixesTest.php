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

    public function test_gmud_invalidates_stale_claims_not_inherited(): void
    {
        // GMUD remove a tabela ZZ0; a regra V0 que cita ZZ0 NÃO pode sobreviver por herança do previous_semantic.
        $prev = [
            'objetivo' => 'x',
            'entendimento_funcional' => ['uma_frase' => ['texto' => 'x', 'confidence' => 'low', 'evidence' => []], 'objetivo' => 'x', 'quando_usado' => 'x',
                'entradas_principais' => [['tipo' => 'tabela', 'nome' => 'ZZ0 (ZZ0_STATUS)', 'descricao' => 'status', 'evidence' => [['type' => 'table', 'table' => 'ZZ0']]]], 'o_que_faz' => []],
            'regras_negocio' => [
                ['id' => 'RN01', 'descricao' => 'Atualiza ZZ0_STATUS ao processar.', 'confidence' => 'high', 'evidence' => [['type' => 'table', 'table' => 'ZZ0']]],
                ['id' => 'RN02', 'descricao' => 'Valida cliente em SA1.', 'confidence' => 'high', 'evidence' => [['type' => 'table', 'table' => 'SA1']]],
            ],
        ];
        $det = [
            'source_type' => 'x', 'language' => 'AdvPL', 'file' => ['filename' => 'A.prw'],
            'functions' => [['name' => 'A', 'type' => 'User Function', 'start_line' => 1, 'end_line' => 6, 'tables' => ['SA1'], 'evidence' => ['line_start' => 1, 'line_end' => 6]]],
            'tables' => [['table' => 'SA1', 'alias' => 'SA1', 'access' => ['READ'], 'functions' => ['A'], 'read_fields' => ['A1_COD']]],
            'queries' => [], 'user_calls' => [], 'dependencies' => [], 'security_findings' => [],
        ];
        $diff = ['diff_stats' => ['change_type' => 'modified', 'structural_change' => true],
            'structural' => ['tables' => ['removed' => [['table' => 'ZZ0']]], 'functions' => ['changed' => [['function' => 'A', 'changes' => ['tables_removed' => ['ZZ0']]]]], 'fields' => ['removed' => [['table' => 'ZZ0', 'field' => 'ZZ0_STATUS']]]]];
        // IA incremental "preguiçosa": não re-decide nada (delta vazio) — a invalidação determinística tem de agir.
        $ai = $this->ai(json_encode(['change_summary' => 'sem mudança relevante', 'updated_functions' => [], 'rules_add' => [], 'rules_update' => [], 'rules_remove' => [], 'attention_add' => []]));
        $r = (new SourceDocSemanticAnalyzer($ai))->analyze($det, 'codigo', $diff, ['previous_semantic' => $prev]);
        $ids = array_map(fn ($x) => $x['id'] ?? '', $r['regras_negocio']);
        $this->assertNotContains('RN01', $ids, 'regra que cita a tabela REMOVIDA não sobrevive por herança');
        $this->assertContains('RN02', $ids, 'regra sobre SA1 (não afetada) permanece');
        // ZZ0 pode (e deve) aparecer na proveniência da invalidação/change_summary; mas NÃO nas CLAIMS.
        $claims = json_encode([$r['regras_negocio'], $r['entendimento_funcional'], $r['dependencias_criticas']], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ZZ0', $claims, 'nenhum vestígio do fato removido nas claims (entrada/regra/dep)');
        $this->assertArrayHasKey('gmud_invalidation', $r);
        $this->assertStringContainsString('GMUD removeu', (string) $r['change_summary'], 'muda funcional é reconhecida (não "sem mudança")');
    }

    public function test_baseline_normalization_numeric_confidence_to_enum(): void
    {
        // baseline Claude com confidence NUMÉRICA (0.95) → enum, sem silenciar p/ low. Registra o que converteu.
        $a = new SourceDocSemanticAnalyzer($this->ai('{}'));
        $sem = ['regras_negocio' => [
            ['id' => 'RN01', 'descricao' => 'x', 'confidence' => 0.95, 'evidence' => [['type' => 'function', 'name' => 'A', 'confidence' => 0.9]]],
            ['id' => 'RN02', 'descricao' => 'y', 'confidence' => 0.6],
            ['id' => 'RN03', 'descricao' => 'z', 'confidence' => 0.3],
            ['id' => 'RN04', 'descricao' => 'w', 'confidence' => 'high'],
        ]];
        $n = $a->normalizeBaseline($sem);
        $c = array_column($n['regras_negocio'], 'confidence');
        $this->assertSame(['high', 'medium', 'low', 'high'], $c, '0.95→high, 0.6→medium, 0.3→low, high mantém');
        $this->assertSame('high', $n['regras_negocio'][0]['evidence'][0]['confidence'], 'normaliza também em profundidade');
        $this->assertGreaterThanOrEqual(4, $n['baseline_normalization']['confidence_numeric_to_enum'], 'registra o que converteu (não silencioso)');
    }

    public function test_cross_source_gmud_removes_dep_and_refreshes_blob(): void
    {
        // GMUD: U_FTelEmail deixou de ser chamado (dep removida); U_PEDVEN continua (só o blob do alvo mudou → refresh).
        $a = new SourceDocSemanticAnalyzer($this->ai('{}'));
        $meta = new \ReflectionProperty(SourceDocSemanticAnalyzer::class, 'crossSourceMeta');
        $meta->setAccessible(true);
        $meta->setValue($a, ['sources' => [['source_doc_id' => 1327, 'symbol' => 'pedven', 'blob_sha' => 'BLOB_NEW']]]);
        $sem = ['dependencias_criticas' => [
            ['nome' => 'U_PEDVEN', 'evidence' => [['level' => 'C', 'source_doc_id' => 1327, 'symbol' => 'pedven', 'relation' => 'calls_user', 'blob_sha' => 'BLOB_OLD']]],
            ['nome' => 'U_FTelEmail', 'evidence' => [['level' => 'C', 'source_doc_id' => 1278, 'symbol' => 'ftelemail', 'relation' => 'calls_user', 'blob_sha' => 'BLOB_FTEL']]],
        ]];
        $det = ['user_calls' => ['U_PEDVEN'], 'functions' => []]; // FTelEmail NÃO está mais nas chamadas do V1
        $m = new \ReflectionMethod(SourceDocSemanticAnalyzer::class, 'applyCrossSourceGmud');
        $m->setAccessible(true);
        $r = $m->invoke($a, $sem, $det);
        $nomes = array_column($r['dependencias_criticas'], 'nome');
        $this->assertContains('U_PEDVEN', $nomes, 'dependência ainda chamada permanece');
        $this->assertNotContains('U_FTelEmail', $nomes, 'dependência cujo símbolo saiu do V1 é podada (determinístico)');
        $ev = $r['dependencias_criticas'][0]['evidence'][0];
        $this->assertSame('BLOB_NEW', $ev['blob_sha'], 'blob refrescado deterministicamente para o atual do alvo');
        $this->assertSame('target_blob_changed', $ev['refresh_reason']);
        $gi = $r['gmud_invalidation'];
        $this->assertSame(1, $gi['dependency_invalidation_count']);
        $this->assertSame(1, $gi['evidence_refresh_count']);
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
