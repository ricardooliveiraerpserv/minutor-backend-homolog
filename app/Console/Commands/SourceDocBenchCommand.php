<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\SourceCode\Analyzer\AdvplAnalyzer;
use App\SourceCode\Analyzer\SecretSanitizer;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;

/**
 * Mede DESEMPENHO e CONTAGENS do AdvplAnalyzer em fontes REAIS grandes (gate de qualidade do Bloco 1).
 * Não imprime o JSON inteiro (fontes de ~1MB); grava o deterministic_json de cada fonte em
 * system_settings ('diag_bench:<n>') e reporta métricas: tempo, memória, linhas, funções, calls,
 * tabelas, queries, efeitos e findings dynamic/unknown. Aceita vários paths.
 *
 *   php artisan source-doc:bench erpserv-clientes jng "Rdmake_PRD/Compilados/nfesefaz.prw"
 *   php artisan source-doc:bench erpserv-clientes concreserv "RPO_PORTAL/Portal/CCSFATIMP.PRW" --branch=main
 */
class SourceDocBenchCommand extends Command
{
    protected $signature = 'source-doc:bench {owner} {repo} {path*} {--branch=} {--store}';
    protected $description = 'Benchmark do AdvplAnalyzer em fontes reais grandes (tempo/memória/contagens).';

    public function handle(GithubAppAuth $auth, AdvplAnalyzer $analyzer, SecretSanitizer $sanitizer): int
    {
        $owner = $this->argument('owner');
        $repo = $this->argument('repo');
        $paths = $this->argument('path');
        $branch = (string) $this->option('branch');
        if ($branch === '') {
            $branch = $auth->getBranchHeadSha($owner, $repo, 'main') ? 'main' : 'master';
        }

        $summary = [];
        $i = 0;
        foreach ($paths as $path) {
            $i++;
            $code = $auth->getFileContent($owner, $repo, $branch, $path);
            if ($code === null) {
                $this->error("[{$path}] não consegui ler @ {$branch}");
                continue;
            }
            $sizeBytes = strlen($code);
            $lines = substr_count($code, "\n") + 1;

            $memBefore = memory_get_usage();
            $t0 = microtime(true);
            $sec = $sanitizer->scan($code);
            $tSan = microtime(true);
            $det = $analyzer->analyze($code, ['path' => $path, 'filename' => basename($path)]);
            $t1 = microtime(true);
            $memPeak = memory_get_peak_usage();

            $det['security_findings'] = $sec['findings'];

            // contagens
            $funcs = count($det['functions']);
            $calls = count($det['call_graph']);
            $tables = count($det['tables']);
            $queries = count($det['queries']);
            $effects = count($det['effects']);
            $dynTables = count(array_filter($det['tables'], fn ($t) => !empty($t['dynamic'])));
            // findings "unknown/null": executor SQL, tabela null em query, campos vazios em query de escrita
            $unknownExec = count(array_filter($det['queries'], fn ($q) => in_array($q['executor'] ?? null, [null, 'SQL'], true)));
            $nullTableQ = count(array_filter($det['queries'], fn ($q) => empty($q['table'])));
            $emptyWriteQ = count(array_filter($det['queries'], fn ($q) => in_array($q['operation'], ['UPDATE', 'INSERT'], true) && empty($q['write_fields'])));
            $riskFlags = 0;
            foreach ($det['queries'] as $q) {
                $riskFlags += count($q['risk_flags'] ?? []);
            }

            $row = [
                'path'          => $path,
                'size_bytes'    => $sizeBytes,
                'size_kb'       => round($sizeBytes / 1024, 1),
                'lines'         => $lines,
                'ms_sanitize'   => round(($tSan - $t0) * 1000, 1),
                'ms_analyze'    => round(($t1 - $tSan) * 1000, 1),
                'ms_total'      => round(($t1 - $t0) * 1000, 1),
                'us_per_line'   => $lines ? round(($t1 - $tSan) * 1e6 / $lines, 1) : null,
                'mem_peak_mb'   => round($memPeak / 1048576, 1),
                'functions'     => $funcs,
                'calls'         => $calls,
                'tables'        => $tables,
                'queries'       => $queries,
                'effects'       => $effects,
                'dynamic_tables' => $dynTables,
                'sql_executor_unknown' => $unknownExec,
                'query_table_null'     => $nullTableQ,
                'update_insert_no_fields' => $emptyWriteQ,
                'risk_flags_total'     => $riskFlags,
                'security_findings'    => count($sec['findings']),
            ];
            $summary[] = $row;

            if ($this->option('store')) {
                SystemSetting::set("diag_bench:{$i}", json_encode(['path' => $path, 'deterministic' => $det], JSON_UNESCAPED_UNICODE), 'string', 'diag');
            }

            $this->line("── {$path}");
            foreach ($row as $k => $v) {
                if ($k === 'path') {
                    continue;
                }
                $this->line(sprintf('   %-24s %s', $k, $v));
            }
        }

        SystemSetting::set('diag_bench', json_encode($summary, JSON_UNESCAPED_UNICODE), 'string', 'diag');
        return self::SUCCESS;
    }
}
