<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\SourceCode\Analyzer\AdvplAnalyzer;
use App\SourceCode\Analyzer\SecretSanitizer;
use App\SourceCode\Analyzer\SourceDiff;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;

/**
 * Fase 1 — valida a extração determinística num fonte REAL do repositório (sem IA).
 * Busca o conteúdo pela GitHub App (read-only), roda sanitizador + AdvplAnalyzer (+ diff se
 * --parent), grava o deterministic_json em system_settings 'diag_analyze' e imprime.
 *
 *   php artisan source-doc:analyze erpserv-clientes jng main FTENVNFE.PRW
 *   php artisan source-doc:analyze erpserv-clientes jng main FTENVNFE.PRW --parent=<sha>
 */
class SourceDocAnalyzeCommand extends Command
{
    protected $signature = 'source-doc:analyze {owner} {repo} {path} {--branch=main} {--parent=} {--semantic}';
    protected $description = 'Fase 1/2: extração determinística (+ semântica com --semantic) de um fonte real.';

    public function handle(GithubAppAuth $auth, AdvplAnalyzer $analyzer, SecretSanitizer $sanitizer, SourceDiff $differ, SourceDocSemanticAnalyzer $semantic): int
    {
        $owner = $this->argument('owner');
        $repo = $this->argument('repo');
        $path = $this->argument('path');
        $branch = (string) $this->option('branch');

        $code = $auth->getFileContent($owner, $repo, $branch, $path);
        if ($code === null) {
            $this->error("Não consegui ler {$owner}/{$repo}@{$branch}:{$path}");
            return self::FAILURE;
        }

        $sec = $sanitizer->scan($code);
        $det = $analyzer->analyze($code, ['path' => $path, 'filename' => basename($path)]);
        $det['security_findings'] = $sec['findings'];

        $diff = null;
        if ($parent = $this->option('parent')) {
            $oldCode = $auth->getFileContent($owner, $repo, $parent, $path);
            $oldDet = $oldCode !== null ? $analyzer->analyze($oldCode, ['path' => $path]) : null;
            $diff = $differ->compare($oldDet, $det, $oldCode, $code);
        } else {
            $diff = $differ->compare(null, $det, null, $code); // criação
        }

        $payload = ['deterministic' => $det, 'diff' => $diff];

        // Camada 2 (opcional) — usa o CÓDIGO MASCARADO (nunca o cru) + fatos + diff.
        if ($this->option('semantic')) {
            $payload['semantic'] = $semantic->analyze($det, $sec['masked'], $diff);
        }

        SystemSetting::set('diag_analyze', json_encode($payload, JSON_UNESCAPED_UNICODE), 'string', 'diag');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
