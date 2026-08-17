<?php

namespace Tests\Feature;

use App\SourceCode\Analyzer\SourceDocAiProvider;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use ReflectionClass;
use Tests\TestCase;

/**
 * Robustez dos fontes GRANDES (top-up/recovery) — os testes obrigatórios do pacote:
 * reserva realista ≤ US$ 0,30, chunk elástico 4→2→1→cost_budget, retry seletivo de bloco,
 * top-up sem repagar o já feito, missing × not_identified, colisão de classe, merge sem perda.
 * DB-free; provider fake.
 */
class SourceDocTopUpRobustnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.source_doc_ai.enabled' => true,
            'services.source_doc_ai.environment' => 'homolog',
            'services.source_doc_ai.allowed_environments' => ['homolog'],
            'services.source_doc_ai.cache_enabled' => false,
            'services.source_doc_ai.simple_route_enabled' => false,
            'services.source_doc_ai.hard_limit_usd' => 0.30,
            'services.source_doc_ai.inline_code_max_chars' => 8000,
            'services.source_doc_ai.block_retry_enabled' => true,
            'services.source_doc_ai.prompt_version' => 2,
        ]);
    }

    private function ai($responder, int $inTok = 120, int $outTok = 60): SourceDocAiProvider
    {
        return new class($responder, $inTok, $outTok) implements SourceDocAiProvider {
            public array $calls = [];
            private $r;
            private int $in;
            private int $out;
            public function __construct($r, int $in, int $out) { $this->r = $r; $this->in = $in; $this->out = $out; }
            public function isConfigured(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function model(): string { return 'fake-1'; }
            public function complete(string $system, string $user, array $opts = []): array
            {
                $i = count($this->calls);
                $this->calls[] = $user;
                $res = is_callable($this->r) ? ($this->r)($user, $i) : $this->r;
                $stop = 'end_turn';
                if (is_array($res)) { $stop = $res['stop'] ?? 'end_turn'; $res = $res['text'] ?? ''; }
                return ['text' => (string) $res, 'usage' => ['input_tokens' => $this->in, 'output_tokens' => $this->out], 'stop' => $stop];
            }
        };
    }

    private function det(int $nFuncs = 2): array
    {
        $fns = [];
        for ($k = 1; $k <= $nFuncs; $k++) {
            $fns[] = ['name' => 'FN' . $k, 'type' => 'Static Function', 'start_line' => $k, 'end_line' => $k,
                'called_by' => $k === 1 ? [] : ['FN1'], 'calls_internal' => [], 'calls_user' => [], 'tables' => ['SPED050'],
                'accesses' => ['UPDATE'], 'effects' => ['database_write'], 'evidence' => ['line_start' => $k, 'line_end' => $k]];
        }
        return [
            'source_type' => 'Fonte Protheus', 'language' => 'AdvPL', 'file' => ['filename' => 'X.PRW'],
            'functions' => $fns,
            'tables' => [['table' => 'SPED050', 'alias' => 'SPED050', 'access' => ['UPDATE'], 'write_fields' => ['EMAIL'], 'where_fields' => ['ID']]],
            'queries' => [['operation' => 'UPDATE', 'table' => 'SPED050', 'function' => 'FN1', 'write_fields' => ['EMAIL'], 'risk_flags' => []]],
            'user_calls' => [], 'external_integrations' => [], 'dependencies' => [], 'effects' => [], 'technical_flow' => [], 'security_findings' => [],
        ];
    }

    private function entBlock(): string
    {
        return json_encode(['entendimento_funcional' => ['uma_frase' => ['texto' => 'Faz X.', 'confidence' => 'medium', 'evidence' => []], 'objetivo' => 'Faz X.', 'quando_usado' => 'sempre', 'o_que_faz' => [['passo' => 'p1', 'evidence' => []]], 'entradas_principais' => [], 'saidas_principais' => [], 'processo_modulo' => ['processo' => 'p', 'modulo' => 'm', 'confidence' => 'low', 'evidence' => []]]]);
    }
    private function rulesBlock(): string { return json_encode(['regras_negocio' => [], 'change_summary' => 'x']); }
    private function depsBlock(): string { return json_encode(['dependencias_criticas' => [], 'risco_alteracao' => ['resumo' => 'r', 'fatores' => []]]); }
    private function funcsBlock(array $names): string
    {
        return json_encode(['funcoes' => array_map(fn ($n) => ['name' => $n, 'finalidade' => 'faz ' . $n, 'confidence' => 'medium', 'evidence' => [['type' => 'table', 'table' => 'SPED050']]], $names)]);
    }

    private function make($ai): SourceDocSemanticAnalyzer { return new SourceDocSemanticAnalyzer($ai); }

    private function priv(object $o, string $m, array $args = [])
    {
        $r = new ReflectionClass($o);
        $mm = $r->getMethod($m); $mm->setAccessible(true);
        return $mm->invokeArgs($o, $args);
    }
    private function setProp(object $o, string $p, $v): void
    {
        $r = new ReflectionClass($o);
        $pp = $r->getProperty($p); $pp->setAccessible(true); $pp->setValue($o, $v);
    }

    // ── Ponto 1+2 — reserva realista + chunk elástico 4→2→1→0 (determinístico via reserva real) ──
    public function test_elastic_fit_4_2_1_0(): void
    {
        $an = $this->make($this->ai(''));
        $items = [];
        for ($k = 1; $k <= 4; $k++) {
            $items[] = ['name' => 'FN' . $k, 'facts' => ['x' => str_repeat('y', 200)], 'code' => str_repeat("z", 300)];
        }
        $cap = 2600;
        // reservas REAIS por tamanho de sub-lote, com output ADAPTATIVO (mesma conta do deepenFitCount).
        $r = fn ($n) => $this->priv($an, 'estimateCallUsd', [$this->priv($an, 'deepenFinalidadesPrompt', [array_slice($items, 0, $n)]), $this->priv($an, 'deepenOutFor', [$n, $cap]), true]) + 0.005;
        [$r4, $r2, $r1] = [$r(4), $r(2), $r(1)];
        $this->assertGreaterThan($r2, $r4, 'reserva de 4 > de 2 (payload + output adaptativo)');
        $this->assertGreaterThan($r1, $r2, 'reserva de 2 > de 1');

        $this->setProp($an, 'costBaseUsd', 0.0);
        // teto entre r2 e r4 ⇒ 4 não cabe, 2 cabe.
        $this->assertSame(2, $this->priv($an, 'deepenFitCount', [$items, $cap, $r2]));
        // teto = r1 ⇒ só 1 cabe.
        $this->assertSame(1, $this->priv($an, 'deepenFitCount', [$items, $cap, $r1]));
        // teto abaixo de r1 ⇒ nem 1 cabe (cost_budget).
        $this->assertSame(0, $this->priv($an, 'deepenFitCount', [$items, $cap, $r1 - 0.001]));
        // INVARIANTE: se cabe n>0, então custo + reserva(n) ≤ teto.
        $n = $this->priv($an, 'deepenFitCount', [$items, $cap, $r2]);
        $this->assertLessThanOrEqual($r2 + 1e-9, 0.0 + $r($n));
    }

    // ── Ponto 4 (cost_budget observável): a guarda IMPEDE o aprofundamento quando não há folga no teto ──
    public function test_cost_budget_blocks_deepening_before_spending(): void
    {
        // provider reporta uso ALTO nos blocos ⇒ ao chegar no aprofundamento não há folga: NENHUMA chamada
        // de função é disparada (a guarda barra ANTES de gastar) e todas viram missing cost_budget.
        config(['services.source_doc_ai.cost_input_per_mtok' => 3.0, 'services.source_doc_ai.cost_output_per_mtok' => 15.0]);
        $ai = $this->ai(fn ($u, $i) => match ($i) { 0 => $this->entBlock(), 1 => $this->rulesBlock(), 2 => $this->depsBlock(), default => $this->funcsBlock(['FN1', 'FN2']) }, 6_000_000, 0);
        $r = $this->make($ai)->analyze($this->det(2), 'codigo', null, []);
        $miss = $r['funcoes_trace']['missing'] ?? [];
        $this->assertCount(2, $miss, 'ambas faltantes por orçamento');
        $this->assertSame('cost_budget', $miss[0]['reason']);
        $this->assertSame('cost_budget', $miss[1]['reason']);
        $this->assertSame(3, count($ai->calls), 'só os 3 blocos foram chamados; aprofundamento barrado ANTES de gastar');
        $this->assertSame([], $r['funcoes_trace']['completed'], 'nenhuma função aprofundada');
    }

    // ── Ponto 3 — retry SOMENTE do bloco truncado (regras), preservando os demais ──
    public function test_retry_only_regras_block(): void
    {
        // regras trunca na 1ª vez (i=1) e recupera no retry; ent/deps/funcoes intactos.
        $ai = $this->ai(fn ($u, $i) => match ($i) {
            0 => $this->entBlock(),
            1 => ['text' => '{"regras_negocio":[{"id":"RN01"', 'stop' => 'max_tokens'], // regras TRUNCADO
            2 => $this->depsBlock(),
            3 => $this->funcsBlock(['FN1', 'FN2']),
            default => json_encode(['regras_negocio' => [['id' => 'RN01', 'titulo' => 't', 'descricao' => 'Grava EMAIL no SPED050', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'EMAIL']]]], 'change_summary' => 'x']), // RETRY regras OK
        });
        $r = $this->make($ai)->analyze($this->det(2), 'codigo', null, []);
        $this->assertSame('ok', $r['block_status']['regras'], 'regras recuperado pelo retry');
        $this->assertSame('ok', $r['block_status']['entendimento']);
        $this->assertSame('ok', $r['block_status']['deps_risco']);
        $this->assertNotEmpty($r['regras_negocio'], 'regra do retry aplicada');
    }

    // ── Ponto 3 — retry SOMENTE do bloco com JSON inválido (deps) ──
    public function test_retry_only_deps_invalid_json(): void
    {
        $ai = $this->ai(fn ($u, $i) => match ($i) {
            0 => $this->entBlock(),
            1 => $this->rulesBlock(),
            2 => 'isto não é json', // deps INVALID_JSON
            3 => $this->funcsBlock(['FN1', 'FN2']),
            default => $this->depsBlock(), // RETRY deps OK
        });
        $r = $this->make($ai)->analyze($this->det(2), 'codigo', null, []);
        $this->assertSame('ok', $r['block_status']['deps_risco'], 'deps recuperado pelo retry');
        $this->assertSame('ok', $r['block_status']['regras']);
    }

    // ── Ponto 4 — TOP-UP não repaga o já feito; só chama para o missing técnico ──
    public function test_topup_recovers_only_missing_without_repaying(): void
    {
        // semantic_json EXISTENTE: 8 completed + 4 missing(cost_budget); top-up só reprocessa os 4.
        $existing = $this->existingPartial(completed: 8, missing: 4, missReason: 'cost_budget');
        $ai = $this->ai(fn ($u, $i) => $this->funcsBlock(['FN9', 'FN10', 'FN11', 'FN12'])); // só as faltantes
        $an = $this->make($ai);
        $r = $an->topUp($existing, $this->det(12), 'codigo', null);
        // as 8 já feitas seguem documentadas (não foram repagas / reprocessadas)
        foreach (['FN1', 'FN2', 'FN8'] as $keep) {
            $this->assertContains($keep, array_column($r['funcoes'], 'name'), "manteve $keep");
        }
        $this->assertContains('FN9', array_column($r['funcoes'], 'name'), 'recuperou FN9');
        $this->assertSame(0, count(array_filter($r['funcoes_trace']['missing'], fn ($m) => in_array($m['reason'], ['cost_budget', 'truncated_unrecovered', 'deepen_call_budget', 'simple_truncated'], true))), 'sem missing técnico após top-up');
        $this->assertSame('completed', $r['status']);
        $this->assertSame('topup_recovery', $r['strategy']);
        // custo adicional registrado separadamente e acumulado ≤ teto.
        $this->assertArrayHasKey('topup_cost_usd', $r['usage']);
        $this->assertLessThanOrEqual(0.30 + 1e-9, (float) $r['usage']['actual_cost_usd']);
        // não houve 12 chamadas de função — só o necessário para 4.
        $this->assertLessThanOrEqual(4, count($ai->calls));
    }

    // ── Ponto 5 — rejeição/ausência de evidência é not_identified, não missing ──
    public function test_evidence_absence_is_not_identified_not_missing(): void
    {
        // aprofundamento devolve FN1 real e FN2 com finalidade UNDETERMINED (sem evidência) — não truncou.
        $ai = $this->ai(fn ($u, $i) => match ($i) {
            0 => $this->entBlock(), 1 => $this->rulesBlock(), 2 => $this->depsBlock(),
            default => json_encode(['funcoes' => [
                ['name' => 'FN1', 'finalidade' => 'faz FN1', 'confidence' => 'medium', 'evidence' => [['type' => 'table', 'table' => 'SPED050']]],
                ['name' => 'FN2', 'finalidade' => 'Não foi possível determinar com segurança.', 'confidence' => 'low', 'evidence' => []],
            ]]),
        });
        $r = $this->make($ai)->analyze($this->det(2), 'codigo', null, []);
        $this->assertContains('FN2', $r['funcoes_trace']['not_identified'], 'sem evidência ⇒ not_identified');
        $this->assertSame([], array_filter($r['funcoes_trace']['missing'], fn ($m) => in_array($m['reason'], ['cost_budget', 'truncated_unrecovered'], true)), 'não vira missing técnico');
        $this->assertSame('completed', $r['status']);
    }

    // ── Ponto 6 — colisão de nome (classe): sub-lote unitário atribui à função canônica; missing não infla ──
    public function test_class_name_collision_does_not_inflate_missing(): void
    {
        // 2 funções; aprofundamento (chunk elástico até 1) devolve SEMPRE o nome da CLASSE 'KLASS'.
        config(['services.source_doc_ai.deepen_chunk_size' => 1]); // força sub-lotes unitários
        $ai = $this->ai(fn ($u, $i) => match ($i) {
            0 => $this->entBlock(), 1 => $this->rulesBlock(), 2 => $this->depsBlock(),
            default => json_encode(['funcoes' => [['name' => 'KLASS', 'finalidade' => 'faz algo', 'confidence' => 'medium', 'evidence' => [['type' => 'table', 'table' => 'SPED050']]]]]),
        });
        $r = $this->make($ai)->analyze($this->det(2), 'codigo', null, []);
        $done = $r['funcoes_trace']['completed'];
        sort($done);
        $this->assertSame(['FN1', 'FN2'], $done, 'nomes canônicos, não a classe');
        $this->assertSame([], $r['funcoes_trace']['missing'], 'colisão não infla missing');
    }

    // ── Ponto (merge) — top-up não perde conteúdo já válido do semantic_json ──
    public function test_topup_merge_preserves_existing_content(): void
    {
        $existing = $this->existingPartial(completed: 8, missing: 4, missReason: 'cost_budget');
        $existing['entendimento_funcional']['objetivo'] = 'OBJETIVO ORIGINAL';
        $existing['regras_negocio'] = [['id' => 'RN01', 'titulo' => 't', 'descricao' => 'Grava EMAIL no SPED050', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'EMAIL']]]];
        $ai = $this->ai(fn ($u, $i) => $this->funcsBlock(['FN9', 'FN10', 'FN11', 'FN12']));
        $r = $this->make($ai)->topUp($existing, $this->det(12), 'codigo', null);
        $this->assertSame('OBJETIVO ORIGINAL', $r['entendimento_funcional']['objetivo'], 'entendimento preservado');
        $this->assertNotEmpty($r['regras_negocio'], 'regras preservadas');
        $this->assertGreaterThanOrEqual(12, count($r['funcoes']), 'funções acumuladas (8 + 4)');
    }

    // ── Refinamento 3 — identidade estável name@start_line em fonte-classe (métodos homônimos) ──
    public function test_class_source_stable_identity_name_at_line(): void
    {
        // det com 2 métodos AMBOS 'KLASS' (nomes colidem); identidade estável = KLASS@1 / KLASS@6.
        $det = $this->det(0);
        $det['functions'] = [
            ['name' => 'KLASS', 'type' => 'Method', 'start_line' => 1, 'end_line' => 5, 'called_by' => [], 'calls_internal' => [], 'calls_user' => [], 'tables' => ['SPED050'], 'accesses' => ['UPDATE'], 'effects' => ['database_write'], 'evidence' => ['line_start' => 1, 'line_end' => 5]],
            ['name' => 'KLASS', 'type' => 'Method', 'start_line' => 6, 'end_line' => 9, 'called_by' => ['KLASS'], 'calls_internal' => [], 'calls_user' => [], 'tables' => ['SPED050'], 'accesses' => ['UPDATE'], 'effects' => ['database_write'], 'evidence' => ['line_start' => 6, 'line_end' => 9]],
        ];
        $ai = $this->ai(fn ($u, $i) => match ($i) {
            0 => $this->entBlock(), 1 => $this->rulesBlock(), 2 => $this->depsBlock(),
            default => $this->funcsBlock(['KLASS@1', 'KLASS@6']), // modelo ecoa a identidade estável
        });
        $r = $this->make($ai)->analyze($det, 'codigo', null, []);
        $done = $r['funcoes_trace']['completed']; sort($done);
        $this->assertSame(['KLASS@1', 'KLASS@6'], $done, 'métodos homônimos distinguidos por linha');
        $this->assertSame([], $r['funcoes_trace']['missing'], 'colisão de classe não infla missing');
        $this->assertCount(2, $r['funcoes'], 'duas funções distintas documentadas (não deduplicadas para 1)');
    }

    // ── Refinamento 1 — retry de bloco NÃO gasta chamada quando não há folga p/ ampliar (truncado) ──
    public function test_adaptive_block_retry_skips_when_no_room(): void
    {
        $an = $this->make($ai = $this->ai(fn ($u, $i) => $this->rulesBlock()));
        // sem folga: custo-base perto do teto ⇒ affordable < mínimo ⇒ não chama.
        $this->setProp($an, 'costBaseUsd', 0.299);
        [$ok, $j] = $this->priv($an, 'retryBlockCall', ['prompt regras longo', 2600, false, 0.30, true]);
        $this->assertFalse($ok);
        $this->assertSame(0, count($ai->calls), 'não gastou chamada inútil sem folga');
        // truncado + folga insuficiente p/ AMPLIAR além do base ⇒ também não chama.
        $this->setProp($an, 'costBaseUsd', 0.0);
        config(['services.source_doc_ai.cost_output_per_mtok' => 15.0, 'services.source_doc_ai.cost_input_per_mtok' => 3.0]);
        // baseOut absurdo (não há como ampliar dentro de 0.30) ⇒ pula.
        [$ok2] = $this->priv($an, 'retryBlockCall', ['x', 900000, false, 0.30, true]);
        $this->assertFalse($ok2, 'truncado sem espaço p/ ampliar ⇒ pula');
    }

    // ── Refinamento 2 — no top-up, FUNÇÕES vêm antes do retry de bloco ──
    public function test_topup_functions_before_block_retry(): void
    {
        // existente: regras TRUNCADO + 2 funções missing técnico. Ordem esperada: deepen (funções) 1º.
        $existing = $this->existingPartial(completed: 10, missing: 2, missReason: 'cost_budget');
        $existing['block_status']['regras'] = 'truncated';
        $ai = $this->ai(fn ($u, $i) => str_contains($u, 'FUNÇÕES RELEVANTES')
            ? $this->funcsBlock(['FN11', 'FN12'])
            : json_encode(['regras_negocio' => [['id' => 'RN01', 'titulo' => 't', 'descricao' => 'Grava EMAIL', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'EMAIL']]]], 'change_summary' => 'x']));
        $this->make($ai)->topUp($existing, $this->det(12), 'codigo', null);
        $this->assertStringContainsString('FUNÇÕES RELEVANTES', $ai->calls[0], 'funções aprofundadas ANTES do retry de bloco');
    }

    // ── Refinamento 4 — output do aprofundamento PROPORCIONAL ao nº de funções ──
    public function test_deepen_output_scales_with_chunk_size(): void
    {
        config(['services.source_doc_ai.deepen_out_base' => 300, 'services.source_doc_ai.deepen_out_per_function' => 450, 'services.source_doc_ai.max_output_tokens_per_call' => 2600]);
        $an = $this->make($this->ai(''));
        $o1 = $this->priv($an, 'deepenOutFor', [1, 2600]);
        $o2 = $this->priv($an, 'deepenOutFor', [2, 2600]);
        $o4 = $this->priv($an, 'deepenOutFor', [4, 2600]);
        $this->assertSame(750, $o1, '1 função → 300 + 450');
        $this->assertSame(1200, $o2, '2 funções');
        $this->assertSame(2100, $o4, '4 funções');
        $this->assertGreaterThan($o1, $o2);
        $this->assertGreaterThan($o2, $o4);
        // nunca acima do cap.
        $this->assertLessThanOrEqual(2600, $this->priv($an, 'deepenOutFor', [20, 2600]));
    }

    // ── Refinamento 4 — output adaptativo CABE numa folga onde o fixo de 2600 NÃO cabia ──
    public function test_adaptive_output_fits_where_fixed_2600_would_not(): void
    {
        config(['services.source_doc_ai.deepen_out_base' => 300, 'services.source_doc_ai.deepen_out_per_function' => 450,
            'services.source_doc_ai.max_output_tokens_per_call' => 2600, 'services.source_doc_ai.cost_output_per_mtok' => 15.0, 'services.source_doc_ai.cost_input_per_mtok' => 3.0]);
        $an = $this->make($this->ai(''));
        $items = [['name' => 'FN1', 'base_name' => 'FN1', 'facts' => ['a' => 'b'], 'code' => '']];
        $hl = 0.30;
        $base = 0.265; // folga = 0.035 — faixa em que 2600 fixos estouram, mas o adaptativo cabe.
        $this->setProp($an, 'costBaseUsd', $base);
        $reserveFixed = $this->priv($an, 'estimateCallUsd', [$this->priv($an, 'deepenFinalidadesPrompt', [$items]), 2600, true]) + 0.005;
        $this->assertGreaterThan($hl - $base, $reserveFixed, 'com 2600 fixos NÃO caberia na folga (0.035)');
        $this->assertSame(1, $this->priv($an, 'deepenFitCount', [$items, 2600, $hl]), 'com output adaptativo, 1 função CABE');
    }

    // ── Refinamento 4 (guarda) — output menor trunca ⇒ retry do MESMO chunk com budget maior ──
    public function test_adaptive_truncation_retries_same_chunk_with_bigger_budget(): void
    {
        config(['services.source_doc_ai.deepen_out_base' => 300, 'services.source_doc_ai.deepen_out_per_function' => 450, 'services.source_doc_ai.deepen_chunk_size' => 1, 'services.source_doc_ai.inline_code_max_chars' => 30]);
        // 1ª chamada de função TRUNCA (out pequeno); retry com budget maior devolve a finalidade.
        $seen = ['n' => 0];
        $ai = $this->ai(function ($u, $i) use (&$seen) {
            if (! str_contains($u, 'FUNÇÕES RELEVANTES')) {
                return match ($i) { 0 => $this->entBlock(), 1 => $this->rulesBlock(), default => $this->depsBlock() };
            }
            $seen['n']++;
            // 1º deepen truncado; 2º (retry maior) ok.
            return $seen['n'] === 1
                ? ['text' => '{"funcoes":[{"name":"FN1"', 'stop' => 'max_tokens']
                : $this->funcsBlock(['FN1']);
        });
        $r = $this->make($ai)->analyze($this->det(1), str_repeat("l\n", 40), null, []);
        $this->assertContains('FN1', $r['funcoes_trace']['completed'], 'retry com budget maior recuperou a função');
        $this->assertGreaterThanOrEqual(2, $seen['n'], 'houve retry do mesmo chunk');
        $this->assertLessThanOrEqual(0.30 + 1e-9, (float) $r['usage']['actual_cost_usd']);
    }

    // ── Ajuste (b) — top-up de fonte-classe casa missing pelo DISPLAY (name@line), não pelo nome-base ──
    public function test_topup_class_source_matches_missing_by_display_identity(): void
    {
        // det com 2 métodos homônimos 'KLASS' (linhas 1 e 6). O missing do trace guarda 'KLASS@6'.
        $det = $this->det(0);
        $det['functions'] = [
            ['name' => 'KLASS', 'type' => 'Method', 'start_line' => 1, 'end_line' => 5, 'called_by' => [], 'calls_internal' => [], 'calls_user' => [], 'tables' => ['SPED050'], 'accesses' => ['UPDATE'], 'effects' => ['database_write'], 'evidence' => ['line_start' => 1, 'line_end' => 5]],
            ['name' => 'KLASS', 'type' => 'Method', 'start_line' => 6, 'end_line' => 9, 'called_by' => ['KLASS'], 'calls_internal' => [], 'calls_user' => [], 'tables' => ['SPED050'], 'accesses' => ['UPDATE'], 'effects' => ['database_write'], 'evidence' => ['line_start' => 6, 'line_end' => 9]],
        ];
        $existing = [
            'schema_version' => 2,
            'block_status' => ['entendimento' => 'ok', 'regras' => 'ok', 'deps_risco' => 'ok', 'funcoes' => 'partial'],
            'funcoes_trace' => ['requested' => ['KLASS@1', 'KLASS@6'], 'completed' => ['KLASS@1'], 'not_identified' => [],
                'missing' => [['name' => 'KLASS@6', 'reason' => 'cost_budget']], 'calls' => 1],
            'entendimento_funcional' => ['uma_frase' => ['texto' => 'x', 'confidence' => 'low', 'evidence' => []], 'objetivo' => 'o', 'quando_usado' => 'q', 'o_que_faz' => [['passo' => 'p', 'evidence' => []]], 'entradas_principais' => [], 'saidas_principais' => [], 'processo_modulo' => ['processo' => 'p', 'modulo' => 'm', 'confidence' => 'low', 'evidence' => []]],
            'dependencias_criticas' => [], 'risco_alteracao' => ['resumo' => 'r', 'fatores' => []],
            'funcoes' => [['name' => 'KLASS@1', 'finalidade' => 'faz 1', 'confidence' => 'medium', 'evidence' => [['type' => 'table', 'table' => 'SPED050']]]],
            'regras_negocio' => [], 'status' => 'partial', 'partial_reason' => 'functions_incomplete', 'strategy' => 'initial_blocks_v3',
            'usage' => ['input_tokens' => 5000, 'output_tokens' => 2000, 'calls' => 3, 'actual_cost_usd' => 0.10, 'hard_limit_usd' => 0.30],
        ];
        $ai = $this->ai(fn ($u, $i) => $this->funcsBlock(['KLASS@6'])); // top-up devolve a identidade display
        $r = $this->make($ai)->topUp($existing, $det, 'codigo', null);
        $this->assertContains('KLASS@6', $r['funcoes_trace']['completed'], 'top-up achou e recuperou o método homônimo faltante');
        $this->assertSame([], $r['funcoes_trace']['missing'], 'sem missing técnico após top-up');
        $this->assertGreaterThanOrEqual(1, count($ai->calls), 'houve chamada de recuperação (missFns não ficou vazio)');
    }

    /** semantic_json parcial sintético: N completed + M missing(cost_budget) sobre det(12). */
    private function existingPartial(int $completed, int $missing, string $missReason): array
    {
        $funcoes = [];
        $comp = [];
        for ($k = 1; $k <= $completed; $k++) {
            $funcoes[] = ['name' => 'FN' . $k, 'finalidade' => 'faz FN' . $k, 'confidence' => 'medium', 'evidence' => [['type' => 'table', 'table' => 'SPED050']]];
            $comp[] = 'FN' . $k;
        }
        $miss = [];
        for ($k = $completed + 1; $k <= $completed + $missing; $k++) {
            $miss[] = ['name' => 'FN' . $k, 'reason' => $missReason];
        }
        $req = array_map(fn ($k) => 'FN' . $k, range(1, $completed + $missing));
        return [
            'schema_version' => 2,
            'block_status' => ['entendimento' => 'ok', 'regras' => 'ok', 'deps_risco' => 'ok', 'funcoes' => 'partial'],
            'funcoes_trace' => ['requested' => $req, 'completed' => $comp, 'not_identified' => [], 'missing' => $miss, 'calls' => 3],
            'entendimento_funcional' => ['uma_frase' => ['texto' => 'x', 'confidence' => 'low', 'evidence' => []], 'objetivo' => 'obj', 'quando_usado' => 'q', 'o_que_faz' => [['passo' => 'p', 'evidence' => []]], 'entradas_principais' => [], 'saidas_principais' => [], 'processo_modulo' => ['processo' => 'p', 'modulo' => 'm', 'confidence' => 'low', 'evidence' => []]],
            'dependencias_criticas' => [],
            'risco_alteracao' => ['resumo' => 'r', 'fatores' => []],
            'funcoes' => $funcoes,
            'regras_negocio' => [],
            'status' => 'partial', 'partial_reason' => 'functions_incomplete', 'strategy' => 'initial_blocks_v3',
            'usage' => ['input_tokens' => 20000, 'output_tokens' => 8000, 'calls' => 5, 'actual_cost_usd' => 0.18, 'hard_limit_usd' => 0.30],
        ];
    }
}
