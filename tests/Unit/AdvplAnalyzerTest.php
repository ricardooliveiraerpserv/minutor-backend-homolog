<?php

namespace Tests\Unit;

use App\SourceCode\Analyzer\AdvplAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Golden/regression tests da camada determinística (AdvplAnalyzer) — Bloco 1.
 * Objetivo: garantir que o analyzer não regrida e não sofra overfitting ao FTENVNFE.
 * Cada fonte de teste declara um conjunto mínimo de expectativas técnicas.
 */
class AdvplAnalyzerTest extends TestCase
{
    private function analyze(string $fixture): array
    {
        $code = file_get_contents(__DIR__ . '/../fixtures/source-doc/' . $fixture);
        return (new AdvplAnalyzer())->analyze($code, ['path' => $fixture, 'filename' => $fixture]);
    }

    private function fn(array $d, string $name): ?array
    {
        foreach ($d['functions'] as $f) {
            if (strcasecmp($f['name'], $name) === 0) {
                return $f;
            }
        }
        return null;
    }

    private function table(array $d, string $alias): ?array
    {
        foreach ($d['tables'] as $t) {
            if ($t['table'] === $alias) {
                return $t;
            }
        }
        return null;
    }

    /** Golden case principal — FTENVNFE.PRW (reenvio de XML de NF-e via UPDATE em SPED050). */
    public function test_ftenvnfe_golden(): void
    {
        $d = $this->analyze('FTENVNFE.PRW');

        $this->assertSame('Fonte Protheus', $d['source_type']);
        $this->assertSame('AdvPL', $d['language']);

        $this->assertNotNull($this->fn($d, 'FTENVNFE'), 'função FTENVNFE');
        $this->assertNotNull($this->fn($d, 'FTENVNFU'), 'função FTENVNFU');

        // relação FTENVNFE -> FTENVNFU, com identidade NORMALIZADA (U_FTENVNFU → FTENVNFU) + called_as
        $edge = array_values(array_filter($d['call_graph'], fn ($e) => strcasecmp($e['from'], 'FTENVNFE') === 0 && stripos($e['to'], 'FTENVNFU') !== false));
        $this->assertNotEmpty($edge, 'call FTENVNFE -> FTENVNFU');
        $this->assertSame('FTENVNFU', $edge[0]['to'], 'to normalizado (sem prefixo U_)');
        $this->assertSame('U_FTENVNFU', $edge[0]['called_as'], 'called_as preserva a sintaxe AdvPL');
        $this->assertSame('internal', $edge[0]['kind'], 'definição local ⇒ internal');
        $this->assertContains('FTENVNFE', $this->fn($d, 'FTENVNFU')['called_by'], 'FTENVNFU called_by FTENVNFE');

        // tabela SPED050 + campos por papel
        $sped = $this->table($d, 'SPED050');
        $this->assertNotNull($sped, 'tabela SPED050');
        $this->assertContains('UPDATE', $sped['access']);
        $this->assertContains('EMAIL', $sped['write_fields']);
        $this->assertContains('STATUSMAIL', $sped['write_fields']);
        $this->assertContains('NFE_ID', $sped['where_fields']);
        $this->assertContains('FTENVNFU', $sped['functions']);

        // SQL detalhado
        $q = $d['queries'][0] ?? null;
        $this->assertNotNull($q, 'query detectada');
        $this->assertSame('UPDATE', $q['operation']);
        $this->assertSame('SPED050', $q['table']);
        $this->assertSame('FTENVNFU', $q['function']);
        $this->assertSame('TCSQLExec', $q['executor']);
        $this->assertSame('concatenation', $q['construction']);
        $this->assertContains('dynamic_sql_by_concatenation', $q['risk_flags']);
        $this->assertContains('EMAIL', $q['write_fields']);
        $this->assertContains('NFE_ID', $q['where_fields']);
        // evidência com line_start/line_end (não só a linha final de montagem)
        $this->assertArrayHasKey('line_start', $q['evidence']);
        $this->assertArrayHasKey('line_end', $q['evidence']);
        $this->assertLessThanOrEqual($q['evidence']['line_end'], $q['evidence']['line_start']);

        // efeitos + integrações + acesso a dados
        $this->assertNotEmpty(array_filter($d['effects'], fn ($e) => $e['type'] === 'database_write'), 'efeito database_write');
        $this->assertSame([], $d['external_integrations'], 'SQL direto NÃO é integração externa');
        $this->assertNotEmpty(array_filter($d['data_access'], fn ($x) => ($x['table'] ?? null) === 'SPED050'), 'data_access sql SPED050');

        // technical_flow estruturado
        $this->assertNotEmpty($d['technical_flow']);
        $this->assertNotEmpty(array_filter($d['technical_flow'], fn ($n) => ($n['type'] ?? '') === 'database_operation' && ($n['table'] ?? '') === 'SPED050'));
    }

    /** Caso sintético (native + REST) — garante generalidade e evita overfitting ao FTENVNFE. */
    public function test_integ_rest_native(): void
    {
        $d = $this->analyze('INTEG_REST.PRW');

        // funções: User + Static
        $this->assertNotNull($this->fn($d, 'ZINTEG'));
        $this->assertSame('Static Function', $this->fn($d, 'ZLOG')['type']);

        // acesso NATIVO a SA1 (DbSelectArea/RecLock/alias->campo)
        $sa1 = $this->table($d, 'SA1');
        $this->assertNotNull($sa1, 'tabela SA1 (nativa)');
        $this->assertContains('native', $sa1['source']);
        $this->assertContains('A1_XSTAT', $sa1['write_fields'], 'SA1->A1_XSTAT := (write)');

        // integração externa REST detectada (FWRest) — e NÃO confundida com acesso a dados
        $this->assertNotEmpty(array_filter($d['external_integrations'], fn ($x) => stripos($x['type'], 'REST') !== false), 'REST em external_integrations');
        $this->assertNotEmpty($d['endpoints'], 'endpoint https detectado');

        // dependência custom externa (U_ZPARSE não definida no fonte)
        $custom = array_column($d['dependencies']['custom_external_functions'], 'name');
        $this->assertContains('U_ZPARSE', $custom, 'U_ZPARSE como dependência externa');

        // efeito external_call (FWRest) + database_write (RecLock em SA1)
        $types = array_column($d['effects'], 'type');
        $this->assertContains('external_call', $types);
        $this->assertContains('database_write', $types);
    }

    /** Anti-alucinação: sem SQL/integração ⇒ listas vazias, não inventadas. */
    public function test_no_hallucination_empty(): void
    {
        $code = "User Function ZNADA()\n Local x := 1\n Return x\n";
        $d = (new AdvplAnalyzer())->analyze($code, ['path' => 'ZNADA.PRW']);
        $this->assertSame([], $d['tables']);
        $this->assertSame([], $d['queries']);
        $this->assertSame([], $d['external_integrations']);
        $this->assertSame([], $d['data_access']);
    }

    /** Classificação por extensão. */
    public function test_language_classification(): void
    {
        $tlpp = (new AdvplAnalyzer())->analyze("function foo()\nreturn\n", ['path' => 'x.tlpp']);
        $this->assertSame('TLPP', $tlpp['language']);
        $this->assertSame('Fonte Protheus', $tlpp['source_type']);
    }
}
