<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\SourceCode\Analyzer\AdvplAnalyzer;
use App\SourceCode\Analyzer\SourceDiff;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;

/**
 * Roda o SourceDiff entre DOIS fontes reais (versão antiga × nova), medindo desempenho.
 * Útil p/ validar o diff estrutural com um antes/depois real de grande porte sem depender de
 * SHAs. Grava o diff em system_settings 'diag_diff' e reporta o diff_stats + tempo/memória.
 *
 *   php artisan source-doc:diffpaths erpserv-clientes jng "Rdmake_PRD/Backups Fontes/V33/nfesefaz.prw" "Rdmake_PRD/Compilados/nfesefaz.prw"
 */
class SourceDocDiffPathsCommand extends Command
{
    protected $signature = 'source-doc:diffpaths {owner} {repo} {oldPath} {newPath} {--branch=main}';
    protected $description = 'Diff estrutural (SourceDiff) entre dois fontes reais, com desempenho.';

    public function handle(GithubAppAuth $auth, AdvplAnalyzer $analyzer, SourceDiff $differ): int
    {
        $owner = $this->argument('owner');
        $repo = $this->argument('repo');
        $branch = (string) $this->option('branch');
        $oldPath = $this->argument('oldPath');
        $newPath = $this->argument('newPath');

        $oldCode = $auth->getFileContent($owner, $repo, $branch, $oldPath);
        $newCode = $auth->getFileContent($owner, $repo, $branch, $newPath);
        if ($oldCode === null || $newCode === null) {
            $this->error('não consegui ler um dos fontes');
            return self::FAILURE;
        }

        $oldDet = $analyzer->analyze($oldCode, ['path' => $oldPath, 'filename' => basename($oldPath)]);
        $newDet = $analyzer->analyze($newCode, ['path' => $newPath, 'filename' => basename($newPath)]);

        $memBefore = memory_get_usage();
        $t0 = microtime(true);
        $diff = $differ->compare($oldDet, $newDet, $oldCode, $newCode);
        $ms = round((microtime(true) - $t0) * 1000, 1);
        $memPeak = round(memory_get_peak_usage() / 1048576, 1);

        SystemSetting::set('diag_diff', json_encode([
            'old' => ['path' => $oldPath, 'lines' => substr_count($oldCode, "\n") + 1, 'bytes' => strlen($oldCode)],
            'new' => ['path' => $newPath, 'lines' => substr_count($newCode, "\n") + 1, 'bytes' => strlen($newCode)],
            'ms_diff' => $ms, 'mem_peak_mb' => $memPeak,
            'diff' => $diff,
        ], JSON_UNESCAPED_UNICODE), 'string', 'diag');

        $this->info("diff em {$ms} ms · pico {$memPeak} MB · change_type={$diff['change_type']} · structural=" . ($diff['structural_change'] ? 'true' : 'false'));
        foreach ($diff['diff_stats'] as $k => $v) {
            $this->line(sprintf('   %-26s %s', $k, is_bool($v) ? ($v ? 'true' : 'false') : $v));
        }
        return self::SUCCESS;
    }
}
