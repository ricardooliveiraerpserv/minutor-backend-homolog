<?php

namespace Tests\Feature;

use App\SourceCode\Analyzer\SourceDocAiProvider;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use Tests\TestCase;

/**
 * Bloco 4.2.1-C — rota SIMPLES (1 chamada) + completude por APLICABILIDADE.
 * Fonte simples sem regra/risco NÃO deve ser 'parcial' só por ter menos conteúdo.
 */
class SourceDocSimpleRouteTest extends TestCase
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
            'services.source_doc_ai.inline_code_max_chars' => 8000,
            'services.source_doc_ai.simple_max_functions' => 3,
        ]);
    }

    /** Fonte simples: 1 função, sem escrita, leitura pura (só READ). */
    private function det(): array
    {
        return [
            'source_type' => 'Fonte Protheus', 'language' => 'AdvPL', 'file' => ['filename' => 'M410INIC.prw'],
            'functions' => [
                ['name' => 'M410INIC', 'type' => 'User Function', 'start_line' => 1, 'end_line' => 40, 'called_by' => [], 'calls_internal' => [], 'calls_user' => [], 'tables' => ['SA1'], 'accesses' => ['READ'], 'effects' => []],
            ],
            'tables' => [['table' => 'SA1', 'alias' => 'SA1', 'access' => ['READ'], 'read_fields' => ['A1_COD'], 'where_fields' => []]],
            'queries' => [], 'user_calls' => [], 'external_integrations' => [], 'dependencies' => [], 'effects' => [], 'technical_flow' => [], 'security_findings' => [],
        ];
    }

    private function ai(int &$calls, $responder): SourceDocAiProvider
    {
        return new class($calls, $responder) implements SourceDocAiProvider {
            public $c; private $r;
            public function __construct(&$c, $r) { $this->c = &$c; $this->r = $r; }
            public function isConfigured(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function model(): string { return 'fake-1'; }
            public function complete(string $s, string $u, array $o = []): array
            { $this->c++; return ['text' => (string) $this->r, 'usage' => ['input_tokens' => 80, 'output_tokens' => 40], 'stop' => 'end_turn']; }
        };
    }

    private function simpleJson(): string
    {
        return json_encode([
            'entendimento_funcional' => [
                'uma_frase' => ['texto' => 'Inicializa o cabeçalho do pedido.', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'M410INIC']]],
                'objetivo' => 'Preenche campos padrão ao abrir a rotina.',
                'quando_usado' => 'Na inicialização da rotina.',
                'processo_modulo' => ['processo' => 'Pedido', 'modulo' => 'Faturamento', 'confidence' => 'low', 'evidence' => [['type' => 'table', 'table' => 'SA1']]],
                'entradas_principais' => [], 'saidas_principais' => [],
                'o_que_faz' => [['passo' => 'Lê dados do cliente', 'evidence' => [['type' => 'table', 'table' => 'SA1']]]],
            ],
            'funcoes' => [['name' => 'M410INIC', 'finalidade' => 'Inicializa campos.', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'M410INIC']]]],
            'regras_negocio' => [],           // sem regra REAL
            'dependencias_criticas' => [],     // sem dep externa
            'risco_alteracao' => ['resumo' => null, 'fatores' => []],
            'change_summary' => 'Documentação inicial.',
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_simple_source_uses_single_call(): void
    {
        $calls = 0;
        $r = (new SourceDocSemanticAnalyzer($this->ai($calls, $this->simpleJson())))
            ->analyze($this->det(), 'codigo simples curto', null, []);
        $this->assertSame(1, $calls, 'fonte simples usa 1 chamada, não 4');
        $this->assertSame('simple_single_call', $r['strategy']);
        $this->assertSame('Preenche campos padrão ao abrir a rotina.', $r['entendimento_funcional']['objetivo']);
    }

    public function test_simple_without_rules_is_complete_not_partial(): void
    {
        // leitura pura, sem regra/risco/dep ⇒ dimensões not_applicable ⇒ COMPLETA (não parcial).
        $calls = 0;
        $r = (new SourceDocSemanticAnalyzer($this->ai($calls, $this->simpleJson())))
            ->analyze($this->det(), 'codigo simples curto', null, []);
        $dim = $r['documentary_completeness']['dimensions'];
        $this->assertSame('not_applicable', $dim['regras_negocio'], 'sem escrita ⇒ regra não se aplica');
        $this->assertSame('not_applicable', $dim['risco'], 'sem fator ⇒ nenhum risco relevante');
        $this->assertSame('not_applicable', $dim['dependencias']);
        $this->assertSame('present', $dim['objetivo']);
        $this->assertSame('completa', $r['documentary_completeness']['level'], 'simples sem regra NÃO é parcial');
    }

    public function test_simple_falls_back_when_truncated(): void
    {
        // simples mas a 1 chamada trunca sem entendimento ⇒ fallback p/ 4 blocos (várias chamadas).
        $calls = 0;
        $ai = $this->ai($calls, ''); // resposta vazia → fallback
        (new SourceDocSemanticAnalyzer($ai))->analyze($this->det(), 'codigo simples curto', null, []);
        $this->assertGreaterThan(1, $calls, 'fallback para a estratégia completa');
    }
}
