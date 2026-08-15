<?php

namespace Tests\Unit;

use App\SourceCode\Analyzer\AdvplAnalyzer;
use App\SourceCode\Analyzer\SourceDiff;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests do SourceDiff (Bloco 2) — diff estrutural-determinístico entre duas versões.
 * Cada cenário monta AdvPL "antes" e "depois", roda o AdvplAnalyzer real e confere o diff.
 */
class SourceDiffTest extends TestCase
{
    private function det(string $code): array
    {
        return (new AdvplAnalyzer())->analyze($code, ['path' => 'X.PRW', 'filename' => 'X.PRW']);
    }

    /** @return array diff entre code antigo (ou null) e novo */
    private function diff(?string $old, string $new): array
    {
        $oldDet = $old === null ? null : $this->det($old);
        return (new SourceDiff())->compare($oldDet, $this->det($new), $old, $new);
    }

    private function changedFn(array $d, string $name): ?array
    {
        foreach ($d['structural']['functions']['changed'] as $c) {
            if (strcasecmp($c['function'], $name) === 0) {
                return $c;
            }
        }
        return null;
    }

    /** 1) primeira versão. */
    public function test_initial_version(): void
    {
        $d = $this->diff(null, "User Function ZA()\nReturn Nil\n");
        $this->assertTrue($d['is_creation']);
        $this->assertSame('initial', $d['change_type']);
        $this->assertSame('initial', $d['diff_stats']['change_type']);
        $this->assertContains('ZA', $d['functions_added']);
    }

    /** 2) função adicionada. */
    public function test_function_added(): void
    {
        $old = "User Function ZA()\nReturn Nil\n";
        $new = "User Function ZA()\nReturn Nil\nStatic Function ZB()\nReturn Nil\n";
        $d = $this->diff($old, $new);
        $this->assertSame('modified', $d['change_type']);
        $this->assertContains('ZB', $d['functions_added']);
        $this->assertSame(1, $d['diff_stats']['functions_added']);
        $this->assertTrue($d['structural_change']);
    }

    /** 3) função removida (+ 12: preserva evidence da versão anterior). */
    public function test_function_removed_keeps_old_evidence(): void
    {
        $old = "User Function ZA()\nReturn Nil\nStatic Function ZB()\nReturn Nil\n";
        $new = "User Function ZA()\nReturn Nil\n";
        $d = $this->diff($old, $new);
        $this->assertContains('ZB', $d['functions_removed']);
        $rem = $d['structural']['functions']['removed'];
        $this->assertNotEmpty($rem);
        $this->assertArrayHasKey('line_start', $rem[0]['evidence'], 'remoção aponta evidence da versão anterior');
    }

    /** 4) função alterada + 10) call adicionada. */
    public function test_function_changed_call_added(): void
    {
        $old = "User Function ZA()\nLocal x := 1\nReturn x\nStatic Function ZB()\nReturn Nil\n";
        $new = "User Function ZA()\nLocal x := 1\nZB()\nReturn x\nStatic Function ZB()\nReturn Nil\n";
        $d = $this->diff($old, $new);
        $c = $this->changedFn($d, 'ZA');
        $this->assertNotNull($c, 'ZA deve constar como alterada');
        $this->assertContains('zb', $c['changes']['calls_added'] ?? []);
        $this->assertGreaterThanOrEqual(1, $d['diff_stats']['calls_added']);
        $edge = array_filter($d['structural']['call_graph']['calls_added'], fn ($e) => strcasecmp($e['from'], 'ZA') === 0 && strcasecmp($e['to'], 'ZB') === 0);
        $this->assertNotEmpty($edge);
    }

    /** 10b) call removida. */
    public function test_call_removed(): void
    {
        $old = "User Function ZA()\nZB()\nReturn Nil\nStatic Function ZB()\nReturn Nil\n";
        $new = "User Function ZA()\nReturn Nil\nStatic Function ZB()\nReturn Nil\n";
        $d = $this->diff($old, $new);
        $this->assertGreaterThanOrEqual(1, $d['diff_stats']['calls_removed']);
        $this->assertNotEmpty($d['structural']['call_graph']['calls_removed']);
    }

    /** 5) tabela adicionada. */
    public function test_table_added(): void
    {
        $old = "User Function ZA()\nDbSelectArea('SA1')\nReturn Nil\n";
        $new = "User Function ZA()\nDbSelectArea('SA1')\nDbSelectArea('SB1')\nReturn Nil\n";
        $d = $this->diff($old, $new);
        $this->assertContains('SB1', $d['tables_added']);
        $this->assertSame(1, $d['diff_stats']['tables_added']);
    }

    /** 6) campo passou de read → write (mudança de papel). */
    public function test_field_role_read_to_write(): void
    {
        $old = "User Function ZA()\nLocal c := SC5->C5_MENNOTA\nReturn Nil\n";
        $new = "User Function ZA()\nSC5->C5_MENNOTA := 'x'\nReturn Nil\n";
        $d = $this->diff($old, $new);
        $ch = array_filter($d['structural']['fields']['changed'], fn ($f) => $f['table'] === 'SC5' && $f['field'] === 'C5_MENNOTA');
        $this->assertNotEmpty($ch, 'C5_MENNOTA deve constar como mudança de papel');
        $ch = array_values($ch)[0];
        $this->assertContains('write', $ch['roles_added']);
        $this->assertContains('read', $ch['roles_removed']);
        $this->assertGreaterThanOrEqual(1, $d['diff_stats']['fields_changed']);
    }

    /** 7) SQL alterado (write_field adicionado numa UPDATE existente). */
    public function test_sql_changed_write_field_added(): void
    {
        $old = "User Function ZA()\nLocal cU := ''\ncU := ' UPDATE SPED050 '\ncU += \" SET EMAIL = 'x' \"\ncU += \" WHERE NFE_ID = '1' \"\nTCSQLExec(cU)\nReturn Nil\n";
        $new = "User Function ZA()\nLocal cU := ''\ncU := ' UPDATE SPED050 '\ncU += \" SET EMAIL = 'x', STATUSMAIL = 'S' \"\ncU += \" WHERE NFE_ID = '1' \"\nTCSQLExec(cU)\nReturn Nil\n";
        $d = $this->diff($old, $new);
        $this->assertSame(1, $d['diff_stats']['sql_operations_changed'], 'UPDATE mesmo alvo com campo novo = changed, não add+remove');
        $ch = $d['structural']['sql']['changed'][0];
        $this->assertSame('UPDATE', $ch['operation']);
        $this->assertSame('SPED050', $ch['table']);
        $this->assertContains('STATUSMAIL', $ch['changes']['write_fields_added'] ?? []);
    }

    /** 8) dependência adicionada (include). */
    public function test_dependency_added(): void
    {
        $old = "#include \"PROTHEUS.CH\"\nUser Function ZA()\nReturn Nil\n";
        $new = "#include \"PROTHEUS.CH\"\n#include \"TBICONN.CH\"\nUser Function ZA()\nReturn Nil\n";
        $d = $this->diff($old, $new);
        $this->assertGreaterThanOrEqual(1, $d['diff_stats']['dependencies_added']);
        $this->assertContains('TBICONN.CH', $d['structural']['dependencies']['includes']['added']);
    }

    /** 9) integração externa adicionada (REST/FWRest) — respeitando "URL solta não é integração". */
    public function test_integration_added(): void
    {
        $old = "User Function ZA()\nReturn Nil\n";
        $new = "User Function ZA()\nLocal oR := FWRest():New('http://api.local/x')\nReturn Nil\n";
        $d = $this->diff($old, $new);
        $this->assertGreaterThanOrEqual(1, $d['diff_stats']['integrations_added']);
        $types = array_column($d['structural']['integrations']['added'], 'type');
        $this->assertNotEmpty(array_filter($types, fn ($t) => stripos($t, 'REST') !== false));
    }

    /** 11) alteração só de comentário/formatação ⇒ structural_change=false (mesmo com linhas +). */
    public function test_comment_only_not_structural(): void
    {
        $old = "User Function ZA()\nLocal x := 1\nReturn x\n";
        $new = "// comentario novo explicando a função\nUser Function ZA()\n\nLocal x := 1    // inline\nReturn x\n";
        $d = $this->diff($old, $new);
        $this->assertFalse($d['structural_change'], 'só comentário/formatação não é mudança estrutural');
        $this->assertGreaterThan(0, $d['diff_stats']['lines_added'], 'ainda assim há linhas adicionadas');
        $this->assertSame(0, $d['diff_stats']['functions_changed']);
    }
}
