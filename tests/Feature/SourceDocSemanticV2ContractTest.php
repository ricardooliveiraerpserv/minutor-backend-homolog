<?php

namespace Tests\Feature;

use App\SourceCode\Analyzer\SourceDocAiProvider;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use Tests\TestCase;

/**
 * Bloco 4.2 — contrato semântico ENRIQUECIDO (Entendimento Funcional) + anti-alucinação dos
 * novos campos + versionamento. DB-free; provider fake. Motor determinístico intocado.
 */
class SourceDocSemanticV2ContractTest extends TestCase
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
            'services.source_doc_ai.simple_route_enabled' => false, // testes do 4-blocos; rota simples tem testes próprios
            'services.source_doc_ai.inline_code_max_chars' => 8000,
        ]);
    }

    private function det(): array
    {
        return [
            'source_type' => 'Fonte Protheus', 'language' => 'AdvPL', 'file' => ['filename' => 'FTENVNFE.PRW'],
            'functions' => [
                ['name' => 'FTENVNFE', 'type' => 'User Function', 'start_line' => 1, 'end_line' => 5, 'called_by' => [], 'calls_internal' => [], 'calls_user' => ['U_FTENVNFU'], 'tables' => [], 'accesses' => [], 'effects' => ['scoped_variable']],
                ['name' => 'FTENVNFU', 'type' => 'Static Function', 'start_line' => 6, 'end_line' => 10, 'called_by' => ['FTENVNFE'], 'calls_internal' => [], 'calls_user' => [], 'tables' => ['SPED050'], 'accesses' => ['UPDATE'], 'effects' => ['database_write']],
            ],
            'tables' => [['table' => 'SPED050', 'alias' => 'SPED050', 'access' => ['UPDATE'], 'write_fields' => ['EMAIL', 'STATUSMAIL'], 'where_fields' => ['NFE_ID']]],
            'queries' => [['operation' => 'UPDATE', 'table' => 'SPED050', 'function' => 'FTENVNFU', 'write_fields' => ['STATUSMAIL'], 'risk_flags' => ['dynamic_sql_by_concatenation']]],
            'user_calls' => ['U_FTENVNFU'], 'external_integrations' => [], 'dependencies' => [], 'effects' => [], 'technical_flow' => [], 'security_findings' => [],
        ];
    }

    private function ai($responder): SourceDocAiProvider
    {
        return new class($responder) implements SourceDocAiProvider {
            private $r;
            public function __construct($r) { $this->r = $r; }
            public function isConfigured(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function model(): string { return 'fake-1'; }
            public function complete(string $system, string $user, array $opts = []): array
            {
                return ['text' => (string) $this->r, 'usage' => ['input_tokens' => 100, 'output_tokens' => 50], 'stop' => 'end_turn'];
            }
        };
    }

    private function richJson(): string
    {
        return json_encode([
            'entendimento_funcional' => [
                'uma_frase' => ['texto' => 'Reenvia o XML da NF-e e atualiza o status de envio.', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]],
                'objetivo' => 'Responsável por reenviar a NF-e por e-mail e registrar o status do envio no SPED050.',
                'quando_usado' => 'Disparado no reenvio de NF-e por e-mail.',
                'processo_modulo' => ['processo' => 'Emissão fiscal', 'modulo' => 'Fiscal', 'confidence' => 'medium', 'evidence' => [['type' => 'table', 'table' => 'SPED050']]],
                'entradas_principais' => [['tipo' => 'parametro', 'nome' => 'cId', 'descricao' => 'Identificador da NF-e', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]]],
                'saidas_principais' => [['tipo' => 'atualizacao', 'nome' => 'SPED050.STATUSMAIL', 'descricao' => 'Grava o status do envio', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'STATUSMAIL']]]],
                'o_que_faz' => [['passo' => 'Recebe o id da NF-e', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]], ['passo' => 'Atualiza o status no SPED050', 'evidence' => [['type' => 'table', 'table' => 'SPED050']]]],
            ],
            'fluxo' => ['Recebe id', 'Atualiza SPED050'],
            'funcoes' => [
                ['name' => 'FTENVNFU', 'finalidade' => 'Grava e-mail e status de envio.', 'confidence' => 'high', 'evidence' => [['type' => 'table', 'table' => 'SPED050']]],
                ['name' => 'FTENVNFE', 'finalidade' => 'Ponto de entrada do reenvio.', 'confidence' => 'medium', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFE']]],
            ],
            'regras_negocio' => [
                ['id' => 'RN01', 'titulo' => 'Marca status de envio', 'descricao' => 'Ao reenviar, grava STATUSMAIL.', 'condicao' => 'Quando a NF-e é reenviada', 'efeito' => 'STATUSMAIL é atualizado', 'confidence' => 'high', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'STATUSMAIL', 'line_start' => 6, 'line_end' => 10]]],
            ],
            'dependencias_criticas' => [
                ['nome' => 'U_FTENVNFU', 'como_participa' => 'Função customizada que efetua o envio.', 'impacto_se_indisponivel' => 'O reenvio não ocorre.', 'confidence' => 'high', 'evidence' => [['type' => 'function', 'name' => 'FTENVNFU']]],
                ['nome' => 'U_NAOEXISTE', 'como_participa' => 'inventada', 'evidence' => []],
            ],
            'risco_alteracao' => [
                'resumo' => 'Alterar afeta a gravação do status fiscal.',
                'fatores' => [
                    ['tipo' => 'escrita', 'descricao' => 'Escreve em SPED050.STATUSMAIL.', 'evidence' => [['type' => 'field', 'table' => 'SPED050', 'field' => 'STATUSMAIL']]],
                    ['tipo' => 'inventado', 'descricao' => 'fator sem evidência', 'evidence' => []],
                ],
            ],
            'pontos_atencao' => [],
            'change_summary' => 'Documentação inicial.',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function go(string $json): array
    {
        return (new SourceDocSemanticAnalyzer($this->ai($json)))->analyze($this->det(), 'codigo curto', null, []);
    }

    public function test_schema_version_bumped(): void
    {
        $this->assertSame(2, SourceDocSemanticAnalyzer::SCHEMA_VERSION);
    }

    public function test_documentary_completeness_complete(): void
    {
        // conteúdo cobre todas as camadas ⇒ qualidade documental 'completa' (independe do status IA)
        $r = $this->go($this->richJson());
        $this->assertSame('completa', $r['documentary_completeness']['level']);
        $this->assertEmpty($r['documentary_completeness']['missing']);
    }

    public function test_documentary_states_4way(): void
    {
        // Bloco 4.2.1-C: objetivo/o_que_faz vazios com o BLOCO ok ⇒ 'not_identified' (VÁLIDO, não penaliza).
        // As 2 funções relevantes não vieram ⇒ finalidades_funcoes = 'missing' (falha recuperável) ⇒ parcial.
        $json = json_encode([
            'entendimento_funcional' => ['uma_frase' => ['texto' => 'x', 'confidence' => 'low', 'evidence' => []], 'objetivo' => '', 'o_que_faz' => []],
            'regras_negocio' => [], 'funcoes' => [], 'change_summary' => 'x',
        ], JSON_UNESCAPED_UNICODE);
        $r = $this->go($json);
        $dim = $r['documentary_completeness']['dimensions'];
        $this->assertSame('not_identified', $dim['objetivo']);
        $this->assertSame('not_identified', $dim['o_que_faz']);
        $this->assertSame('missing', $dim['finalidades_funcoes']);
        $this->assertSame('parcial', $r['documentary_completeness']['level']);
        $this->assertContains('finalidades_funcoes', $r['documentary_completeness']['missing']);
    }

    public function test_entendimento_funcional_built(): void
    {
        $r = $this->go($this->richJson());
        $ent = $r['entendimento_funcional'];
        $this->assertStringContainsString('Reenvia o XML', $ent['uma_frase']['texto']);
        $this->assertSame('high', $ent['uma_frase']['confidence']);
        $this->assertStringContainsString('reenviar a NF-e', $ent['objetivo']);
        $this->assertStringContainsString('reenvio', $ent['quando_usado']);
        $this->assertSame('Fiscal', $ent['processo_modulo']['modulo']);
        $this->assertNotEmpty($ent['entradas_principais']);
        $this->assertNotEmpty($ent['saidas_principais']);
        $this->assertCount(2, $ent['o_que_faz']);
    }

    public function test_funcoes_enriched_with_confidence_and_evidence(): void
    {
        $r = $this->go($this->richJson());
        $f = collect($r['funcoes'])->firstWhere('name', 'FTENVNFU');
        $this->assertSame('high', $f['confidence']);
        $this->assertNotEmpty($f['evidence']);
    }

    public function test_regras_carry_titulo_condicao_efeito(): void
    {
        $r = $this->go($this->richJson());
        $rn = $r['regras_negocio'][0];
        $this->assertSame('Marca status de envio', $rn['titulo']);
        $this->assertNotEmpty($rn['condicao']);
        $this->assertNotEmpty($rn['efeito']);
    }

    public function test_dependencias_criticas_reject_hallucinated(): void
    {
        $r = $this->go($this->richJson());
        $nomes = array_column($r['dependencias_criticas'], 'nome');
        $this->assertContains('U_FTENVNFU', $nomes);
        $this->assertNotContains('U_NAOEXISTE', $nomes, 'dependência inexistente deve ser rejeitada');
        $this->assertGreaterThan(0, $r['validation']['rejected_count']);
    }

    public function test_risco_factors_require_evidence(): void
    {
        $r = $this->go($this->richJson());
        $fatores = $r['risco_alteracao']['fatores'];
        $this->assertCount(1, $fatores, 'fator sem evidência deve ser descartado');
        $this->assertSame('escrita', $fatores[0]['tipo']);
    }

    public function test_undetermined_when_no_evidence(): void
    {
        // uma_frase SEM evidence → vira "Não foi possível determinar com segurança."
        $json = json_encode([
            'entendimento_funcional' => [
                'uma_frase' => ['texto' => 'Chute sem evidência.', 'confidence' => 'high', 'evidence' => []],
                'objetivo' => '',
            ],
            'funcoes' => [], 'regras_negocio' => [], 'change_summary' => 'x',
        ], JSON_UNESCAPED_UNICODE);
        $r = $this->go($json);
        $this->assertSame('Não foi possível determinar com segurança.', $r['entendimento_funcional']['uma_frase']['texto']);
        $this->assertSame('Não foi possível determinar com segurança.', $r['entendimento_funcional']['objetivo']);
    }
}
