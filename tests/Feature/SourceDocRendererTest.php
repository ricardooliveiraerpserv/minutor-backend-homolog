<?php

namespace Tests\Feature;

use App\SourceCode\SourceDocRenderer;
use Tests\TestCase;

/**
 * Bloco 5 — o Renderer APRESENTA (não descobre). Testa composição, escala, tolerância a documentos
 * antigos, status, histórico, diff, separação acesso-a-dados × integrações e ausência de segredos.
 * DB-free (a suíte do projeto é DB-free). docx() é exercitado como smoke (bytes > 0, sem exceção).
 */
class SourceDocRendererTest extends TestCase
{
    private function baseDoc(array $overrides = []): array
    {
        $doc = [
            'status' => 'analyzing',
            'identity' => ['filename' => 'FTENVNFE.PRW', 'owner' => 'erpserv-clientes', 'repository' => 'jng', 'branch' => 'main', 'path' => 'FTENVNFE.PRW', 'lang' => 'AdvPL', 'customer_id' => 10],
            'version' => ['source_commit_sha' => '390d560bc880aa', 'ticket_number' => 'GMUD-1', 'responsavel' => 'Fulano', 'source_blob_sha' => '9f06a64c1ac2'],
            'deterministic' => [
                'functions' => [
                    ['name' => 'FTENVNFE', 'type' => 'User Function', 'start_line' => 35, 'end_line' => 40, 'params' => [], 'returns' => ['Nil'], 'calls_internal' => [], 'calls_user' => ['U_FTENVNFU'], 'called_by' => [], 'tables' => [], 'accesses' => [], 'effects' => ['scoped_variable']],
                    ['name' => 'FTENVNFU', 'type' => 'User Function', 'start_line' => 41, 'end_line' => 52, 'params' => ['cId', 'cEmail'], 'returns' => ['Nil'], 'calls_internal' => [], 'calls_user' => [], 'called_by' => ['FTENVNFE'], 'tables' => ['SPED050'], 'accesses' => ['UPDATE'], 'effects' => ['database_write']],
                ],
                'call_graph' => [['from' => 'FTENVNFE', 'to' => 'FTENVNFU', 'called_as' => 'U_FTENVNFU', 'kind' => 'internal']],
                'tables' => [['table' => 'SPED050', 'alias' => 'SPED050', 'access' => ['UPDATE'], 'functions' => ['FTENVNFU'], 'read_fields' => [], 'write_fields' => ['EMAIL', 'STATUSMAIL'], 'where_fields' => ['NFE_ID'], 'source' => ['sql'], 'dynamic' => false, 'evidence' => ['line_start' => 40, 'line_end' => 42]]],
                'queries' => [['operation' => 'UPDATE', 'table' => 'SPED050', 'executor' => 'TCSQLExec', 'function' => 'FTENVNFU', 'construction' => 'concatenation', 'read_fields' => [], 'write_fields' => ['EMAIL', 'STATUSMAIL'], 'where_fields' => ['NFE_ID'], 'has_where' => true, 'risk_flags' => ['dynamic_sql_by_concatenation'], 'evidence' => ['line' => 40, 'line_start' => 40, 'line_end' => 42]]],
                'data_access' => [['type' => 'sql', 'operation' => 'UPDATE', 'table' => 'SPED050', 'function' => 'FTENVNFU', 'executor' => 'TCSQLExec', 'evidence' => ['line' => 40]]],
                'external_integrations' => [], 'endpoints' => [],
                'dependencies' => ['includes' => ['PROTHEUS.CH'], 'internal_functions' => ['FTENVNFE', 'FTENVNFU'], 'custom_external_functions' => [], 'totvs_framework_functions' => ['TCSQLExec'], 'classes' => [], 'apis' => []],
                'effects' => [['type' => 'database_write', 'target' => 'SPED050', 'function' => 'FTENVNFU', 'evidence' => ['line' => 40]]],
                'technical_flow' => [['type' => 'function', 'name' => 'FTENVNFE'], ['type' => 'function_call', 'from' => 'FTENVNFE', 'to' => 'FTENVNFU', 'called_as' => 'U_FTENVNFU'], ['type' => 'function', 'name' => 'FTENVNFU'], ['type' => 'database_operation', 'operation' => 'UPDATE', 'table' => 'SPED050', 'function' => 'FTENVNFU']],
            ],
            'semantic' => null,
            'diff' => ['diff_stats' => ['change_type' => 'initial', 'structural_change' => true, 'lines_added' => 52, 'lines_removed' => 0]],
            'security_findings' => [],
        ];
        return array_replace_recursive($doc, $overrides);
    }

    private function ctx(string $status = 'ATUALIZADA', array $extra = []): array
    {
        return array_replace_recursive([
            'status' => ['status' => $status, 'documented_blob_sha' => '9f06a64c1ac2', 'current_blob_sha' => '9f06a64c1ac2', 'source_commit_sha' => '390d560b', 'reason' => null, 'checked_at' => '2026-08-15T11:00:40+00:00'],
            'versions' => [['created_at' => '2026-08-15 11:00:00', 'ticket_number' => 'GMUD-1', 'responsavel' => 'Fulano', 'source_commit_sha' => '390d560b', 'source_blob_sha' => '9f06a64c', 'analysis_status' => 'analyzing', 'structural_change' => true, 'resumo' => null]],
        ], $extra);
    }

    private function r(): SourceDocRenderer
    {
        return new SourceDocRenderer();
    }

    /** 1) fonte pequeno — compõe html e docx. */
    public function test_small_source_html_and_docx(): void
    {
        $html = $this->r()->html($this->baseDoc(), false, ['name' => 'JNG'], $this->ctx());
        $this->assertStringContainsString('FTENVNFU', $html);
        $this->assertStringContainsString('SPED050', $html);
        $docx = $this->r()->docx($this->baseDoc(), false, ['name' => 'JNG'], $this->ctx());
        $this->assertGreaterThan(2000, strlen($docx));
    }

    /** 2) fonte grande — resumo + nota de agrupamento; docx não estoura/quebra. */
    public function test_large_source_groups_and_renders(): void
    {
        $fns = [];
        for ($i = 0; $i < 80; $i++) {
            $fns[] = ['name' => "FUN{$i}", 'type' => 'Static Function', 'start_line' => $i * 10, 'end_line' => $i * 10 + 5, 'params' => [], 'returns' => [], 'calls_internal' => [], 'calls_user' => [], 'called_by' => [], 'tables' => [], 'accesses' => [], 'effects' => []];
        }
        $doc = $this->baseDoc(['deterministic' => ['functions' => $fns]]);
        $html = $this->r()->html($doc, false, [], $this->ctx());
        $this->assertStringContainsString('utilitária', $html, 'fonte grande deve indicar funções só no resumo');
        $docx = $this->r()->docx($doc, false, [], $this->ctx());
        $this->assertGreaterThan(2000, strlen($docx));
    }

    /** 3) sem semantic_json — visão geral pendente, mas fatos completos. */
    public function test_without_semantic(): void
    {
        $html = $this->r()->html($this->baseDoc(), false, [], $this->ctx());
        $this->assertStringContainsString('Descrição funcional ainda não disponível', $html);
        $this->assertStringContainsString('SPED050', $html);
        $this->assertStringContainsString('Regras funcionais ainda não analisadas', $html);
    }

    /** 4) com semantic_json — objetivo e regras aparecem. */
    public function test_with_semantic(): void
    {
        $doc = $this->baseDoc(['semantic' => ['status' => 'completed', 'objetivo' => 'Reenvia o XML da NF-e por e-mail.', 'regras_negocio' => [['id' => 'RN01', 'descricao' => 'Atualiza status do envio.']], 'funcoes' => [['name' => 'FTENVNFU', 'finalidade' => 'Grava o e-mail.']]]]);
        $html = $this->r()->html($doc, false, [], $this->ctx());
        $this->assertStringContainsString('Reenvia o XML da NF-e', $html);
        $this->assertStringContainsString('RN01', $html);
        $this->assertStringContainsString('Grava o e-mail', $html);
    }

    /** 5) versão antiga com chaves ausentes — tolerante, sem quebrar. */
    public function test_old_document_missing_keys(): void
    {
        $doc = [
            'identity' => ['filename' => 'ANTIGO.PRW', 'lang' => 'AdvPL'],
            'version' => ['source_commit_sha' => 'abc123'],
            'deterministic' => [
                // tabela antiga sem read/write/where_fields; função sem called_by/effects
                'functions' => [['name' => 'ZOLD', 'type' => 'Function']],
                'tables' => [['alias' => 'SA1', 'access' => ['READ'], 'fields' => ['A1_NOME']]],
                'queries' => [], 'external_integrations' => [],
            ],
        ];
        $html = $this->r()->html($doc, false, [], []); // sem context (retrocompatível)
        $this->assertStringContainsString('SA1', $html);
        $this->assertStringContainsString('ANTIGO.PRW', $html);
        $docx = $this->r()->docx($doc, false, [], []);
        $this->assertGreaterThan(1500, strlen($docx));
    }

    /** 6/7/8) status ATUALIZADA / DESATUALIZADA / NAO_VALIDADO. */
    public function test_status_updated(): void
    {
        $html = $this->r()->html($this->baseDoc(), false, [], $this->ctx('ATUALIZADA'));
        $this->assertStringContainsString('Status da documentação: ATUALIZADA', $html);
    }

    public function test_status_outdated(): void
    {
        $ctx = $this->ctx('DESATUALIZADA', ['status' => ['documented_blob_sha' => 'aaaa1111', 'current_blob_sha' => 'bbbb2222']]);
        $html = $this->r()->html($this->baseDoc(), true, [], $ctx);
        $this->assertStringContainsString('DESATUALIZADA', $html);
        $this->assertStringContainsString('aaaa1111', $html);
        $this->assertStringContainsString('bbbb2222', $html);
    }

    public function test_status_unverified_friendly_reason(): void
    {
        $ctx = $this->ctx('NAO_VALIDADO', ['status' => ['reason' => 'missing_documented_sha']]);
        $html = $this->r()->html($this->baseDoc(), false, [], $ctx);
        $this->assertStringContainsString('NÃO VALIDADA', $html);
        $this->assertStringContainsString('SHA de conteúdo não disponível', $html, 'motivo amigável');
        $this->assertStringNotContainsString('missing_documented_sha', $html, 'não expor o código técnico ao usuário final');
    }

    /** 9) múltiplas versões no histórico. */
    public function test_history_multiple_versions(): void
    {
        $ctx = $this->ctx('ATUALIZADA', ['versions' => [
            ['created_at' => '2026-07-01 09:00:00', 'ticket_number' => 'GMUD-9', 'responsavel' => 'Ana', 'source_commit_sha' => 'ddd', 'source_blob_sha' => 'eee', 'analysis_status' => 'completed', 'structural_change' => true, 'resumo' => 'Ajuste.'],
            ['created_at' => '2026-02-01 09:00:00', 'ticket_number' => 'GMUD-1', 'responsavel' => 'Bob', 'source_commit_sha' => 'aaa', 'source_blob_sha' => 'bbb', 'analysis_status' => 'completed', 'structural_change' => false, 'resumo' => null],
        ]]);
        $html = $this->r()->html($this->baseDoc(), false, [], $ctx);
        $this->assertStringContainsString('GMUD-9', $html);
        $this->assertStringContainsString('GMUD-1', $html);
        $this->assertStringContainsString('Não estrutural', $html);
    }

    /** 10) structural_change=false no diff. */
    public function test_diff_non_structural(): void
    {
        $doc = $this->baseDoc(['diff' => ['diff_stats' => ['change_type' => 'modified', 'structural_change' => false, 'lines_added' => 3, 'lines_removed' => 1]]]);
        $html = $this->r()->html($doc, false, [], $this->ctx());
        $this->assertStringContainsString('não estrutural', $html);
    }

    /** 11) tabelas: leitura/escrita/filtro separados. */
    public function test_tables_read_write_where_separated(): void
    {
        $html = $this->r()->html($this->baseDoc(), false, [], $this->ctx());
        $this->assertStringContainsString('Campos alterados', $html);
        $this->assertStringContainsString('STATUSMAIL', $html);
        $this->assertStringContainsString('Campos em filtro', $html);
        $this->assertStringContainsString('NFE_ID', $html);
    }

    /** 12) SQL detalhado. */
    public function test_sql_detailed(): void
    {
        $html = $this->r()->html($this->baseDoc(), false, [], $this->ctx());
        $this->assertStringContainsString('TCSQLExec', $html);
        $this->assertStringContainsString('concatenation', $html);
        $this->assertStringContainsString('dynamic_sql_by_concatenation', $html);
    }

    /** 13) acesso a dados × integrações — SQL não vira "integração". */
    public function test_data_access_vs_integrations(): void
    {
        $html = $this->r()->html($this->baseDoc(), false, [], $this->ctx());
        $this->assertStringContainsString('Acesso a Dados', $html);
        $this->assertStringContainsString('Nenhuma integração externa identificada automaticamente', $html);
    }

    /** 14) segredos nunca aparecem (só type/location/severity dos findings). */
    public function test_secrets_never_rendered(): void
    {
        $doc = $this->baseDoc(['security_findings' => [['type' => 'aws_access_key', 'location' => '17', 'severity' => 'high']]]);
        $html = $this->r()->html($doc, false, [], $this->ctx());
        $this->assertStringContainsString('aws_access_key', $html);
        $this->assertStringContainsString('high', $html);
        // um valor de segredo hipotético jamais é renderizado (o modelo nunca carrega o valor)
        $this->assertStringNotContainsString('AKIA', $html);
    }
}
