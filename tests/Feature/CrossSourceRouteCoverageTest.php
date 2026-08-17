<?php

namespace Tests\Feature;

use App\SourceCode\Analyzer\SourceDocAiProvider;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use Tests\TestCase;

/**
 * Gate 3B — COBERTURA DE ROTAS: prova que o bloco cross-source é injetado em TODA rota que produz
 * semântica (simple, multi-bloco, deepening, incremental, top-up) e que a proveniência reflete a
 * injeção REAL (o que foi enviado), não a intenção. DB-free; provider fake captura os prompts.
 */
class CrossSourceRouteCoverageTest extends TestCase
{
    private const MARKER = 'CONTEXTO CROSS-SOURCE (AUXILIAR';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.source_doc_ai.enabled' => true,
            'services.source_doc_ai.environment' => 'homolog',
            'services.source_doc_ai.allowed_environments' => ['homolog'],
            'services.source_doc_ai.cache_enabled' => false,
            'services.source_doc_ai.hard_limit_usd' => 0.30,
            'services.source_doc_ai.max_relevant_functions' => 12,
            'services.source_doc_ai.inline_code_max_chars' => 8000,
            'services.source_doc_ai.prompt_version' => 2,
        ]);
    }

    /** contexto cross-source materializado (1 fonte) — faz crossSourceBlock() não-vazio. */
    private function ctx(array $over = []): array
    {
        return array_merge([
            'cross_source' => [
                'enabled' => true, 'fingerprint' => 'fpTEST',
                'telemetry' => ['resolved' => 1],
                'sources' => [[
                    'source_doc_id' => 2096, 'path' => 'Financeiro/CLRFIN01.PRW', 'blob_sha' => 'blobT',
                    'symbol' => 'clrfin01', 'relation' => 'calls_user',
                    'facts' => ['function' => 'CLRFIN01', 'tables' => [['table' => 'SE1', 'access' => ['READ']]]],
                    'facts_strategy' => 'file_bounded_fallback', 'facts_included' => true,
                    'snippet_included' => false, 'snippet_skipped_reason' => 'facts_first_sufficient',
                    'estimated_context_tokens' => 120,
                ]],
            ],
        ], $over);
    }

    private function ai(): object
    {
        return new class implements SourceDocAiProvider {
            public array $prompts = [];
            public function isConfigured(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function model(): string { return 'fake-1'; }
            public function complete(string $system, string $user, array $opts = []): array
            {
                $this->prompts[] = $user;
                // responde JSON mínimo válido conforme o bloco pedido (mantém a rota viva).
                if (str_contains($user, 'Fonte SIMPLES')) {
                    $t = '{"entendimento_funcional":{"uma_frase":{"texto":"x","confidence":"low","evidence":[]},"objetivo":"x","quando_usado":"x","o_que_faz":[]},"funcoes":[],"regras_negocio":[],"dependencias_criticas":[],"risco_alteracao":{"resumo":"x","fatores":[]},"change_summary":"x"}';
                } elseif (str_contains($user, 'FUNÇÕES RELEVANTES')) {
                    $t = '{"funcoes":[{"name":"F1","finalidade":"y","confidence":"low","evidence":[]}],"regras_negocio":[],"pontos_atencao":[],"dependencias_criticas":[]}';
                } elseif (str_contains($user, 'Responda SOMENTE o que muda')) {
                    $t = '{"change_summary":"x","updated_functions":[],"rules_add":[],"rules_update":[],"rules_remove":[],"attention_add":[]}';
                } elseif (str_contains($user, 'entendimento_funcional')) {
                    $t = '{"entendimento_funcional":{"uma_frase":{"texto":"x","confidence":"low","evidence":[]},"objetivo":"x","quando_usado":"x","o_que_faz":[]},"fluxo":[]}';
                } elseif (str_contains($user, 'regras_negocio[')) {
                    $t = '{"regras_negocio":[],"change_summary":"x"}';
                } else {
                    $t = '{"risco_alteracao":{"resumo":"x","fatores":[]},"dependencias_criticas":[],"pontos_atencao":[]}';
                }
                return ['text' => $t, 'usage' => ['input_tokens' => 100, 'output_tokens' => 40], 'stop' => 'end_turn'];
            }
        };
    }

    private function detSimple(): array
    {
        return ['source_type' => 'x', 'language' => 'AdvPL', 'file' => ['filename' => 'A.prw'],
            'functions' => [['name' => 'A', 'type' => 'User Function', 'start_line' => 1, 'end_line' => 5, 'calls_user' => ['U_CLRFIN01'], 'tables' => [], 'evidence' => ['line_start' => 1, 'line_end' => 5]]],
            'tables' => [], 'queries' => [], 'user_calls' => ['U_CLRFIN01'], 'dependencies' => [], 'security_findings' => []];
    }

    private function detMulti(): array
    {
        $fns = [];
        foreach (range(1, 6) as $i) {
            $fns[] = ['name' => "F$i", 'type' => 'User Function', 'start_line' => $i * 10, 'end_line' => $i * 10 + 8,
                'calls_user' => ['U_CLRFIN01'], 'tables' => ['SE1'], 'accesses' => ['UPDATE'], 'evidence' => ['line_start' => $i * 10, 'line_end' => $i * 10 + 8]];
        }
        return ['source_type' => 'x', 'language' => 'AdvPL', 'file' => ['filename' => 'B.prw'], 'functions' => $fns,
            'tables' => [['table' => 'SE1', 'alias' => 'SE1', 'access' => ['UPDATE'], 'functions' => ['F1'], 'write_fields' => ['X'], 'read_fields' => []]],
            'queries' => [], 'user_calls' => ['U_CLRFIN01'], 'dependencies' => [], 'security_findings' => []];
    }

    private function marked(array $prompts): int
    {
        return count(array_filter($prompts, fn ($p) => str_contains($p, self::MARKER)));
    }

    public function test_simple_route_injects(): void
    {
        config(['services.source_doc_ai.simple_route_enabled' => true, 'services.source_doc_ai.simple_max_functions' => 3]);
        $ai = $this->ai();
        $sem = (new SourceDocSemanticAnalyzer($ai))->analyze($this->detSimple(), 'cod', null, $this->ctx());
        $this->assertGreaterThanOrEqual(1, $this->marked($ai->prompts), 'rota simples deve injetar o contexto');
        $this->assertTrue($sem['cross_source']['injected']);
        $this->assertGreaterThan(0, $sem['cross_source']['cost']['added_input_tokens']);
    }

    public function test_multiblock_and_deepening_inject(): void
    {
        config(['services.source_doc_ai.simple_route_enabled' => false]);
        $ai = $this->ai();
        $sem = (new SourceDocSemanticAnalyzer($ai))->analyze($this->detMulti(), 'cod', null, $this->ctx());
        // entendimento + regras + depRisco + ao menos 1 deepening = ≥4 prompts marcados
        $this->assertGreaterThanOrEqual(4, $this->marked($ai->prompts), 'multi-bloco + deepening devem injetar');
        $this->assertSame($this->marked($ai->prompts), $sem['cross_source']['injected_calls'], 'proveniência = injeção real');
    }

    public function test_incremental_route_injects(): void
    {
        config(['services.source_doc_ai.simple_route_enabled' => false]);
        $ai = $this->ai();
        $diff = ['diff_stats' => ['change_type' => 'modified', 'structural_change' => true], 'functions_changed' => ['A']];
        $prev = ['objetivo' => 'ant', 'regras_negocio' => []];
        (new SourceDocSemanticAnalyzer($ai))->analyze($this->detSimple(), 'cod', $diff, $this->ctx(['previous_semantic' => $prev]));
        $this->assertGreaterThanOrEqual(1, $this->marked($ai->prompts), 'rota incremental deve injetar');
    }

    public function test_topup_route_injects(): void
    {
        $ai = $this->ai();
        $existing = ['status' => 'partial', 'usage' => ['actual_cost_usd' => 0.05],
            'block_status' => ['entendimento' => 'truncated', 'regras' => 'ok', 'deps_risco' => 'ok'],
            'funcoes_trace' => ['requested' => [], 'completed' => [], 'missing' => []], 'funcoes' => [], 'regras_negocio' => []];
        (new SourceDocSemanticAnalyzer($ai))->topUp($existing, $this->detSimple(), 'cod', null, $this->ctx());
        $this->assertGreaterThanOrEqual(1, $this->marked($ai->prompts), 'top-up deve injetar ao re-produzir bloco');
    }

    public function test_off_and_self_contained_never_inject(): void
    {
        config(['services.source_doc_ai.simple_route_enabled' => true]);
        $ai = $this->ai();
        $sem = (new SourceDocSemanticAnalyzer($ai))->analyze($this->detSimple(), 'cod', null, []); // sem ctx
        $this->assertSame(0, $this->marked($ai->prompts), 'self-contained NUNCA injeta');
        $this->assertFalse(($sem['cross_source'] ?? ['enabled' => false])['enabled']);
    }

    public function test_provenance_resolved_but_not_injected(): void
    {
        // contexto RESOLVIDO mas SEM fontes materializadas ⇒ nada injetado ⇒ injected=false, custo 0.
        config(['services.source_doc_ai.simple_route_enabled' => true]);
        $ai = $this->ai();
        $ctx = ['cross_source' => ['enabled' => true, 'fingerprint' => 'fpX', 'telemetry' => ['resolved' => 2], 'sources' => []]];
        $sem = (new SourceDocSemanticAnalyzer($ai))->analyze($this->detSimple(), 'cod', null, $ctx);
        $this->assertSame(0, $this->marked($ai->prompts));
        $xs = $sem['cross_source'];
        $this->assertSame(2, $xs['resolved']);
        $this->assertSame(0, $xs['materialized']);
        $this->assertFalse($xs['injected']);
        $this->assertSame(0, $xs['cost']['added_input_tokens']);
        $this->assertSame(0.0, $xs['cost']['added_cost_usd']);
    }

    public function test_hard_limit_holds_with_cross_source(): void
    {
        // hard-limit minúsculo + rota simples ⇒ estimativa (com contexto) estoura ⇒ skip por custo, sem violar.
        config(['services.source_doc_ai.simple_route_enabled' => true, 'services.source_doc_ai.hard_limit_usd' => 0.00001]);
        $ai = $this->ai();
        $sem = (new SourceDocSemanticAnalyzer($ai))->analyze($this->detSimple(), 'cod', null, $this->ctx());
        $this->assertSame('skipped_cost_limit', $sem['status'], 'guarda de custo permanece inviolável com contexto');
    }
}
