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
    protected $signature = 'source-doc:analyze {owner} {repo} {path} {--branch=main} {--parent=} {--semantic} {--persist}';
    protected $description = 'Fase 1/2/3: extração determinística (+ semântica com --semantic) (+ pipeline/persistência com --persist).';

    public function handle(GithubAppAuth $auth, AdvplAnalyzer $analyzer, SecretSanitizer $sanitizer, SourceDiff $differ, SourceDocSemanticAnalyzer $semantic): int
    {
        // --persist: valida o pipeline completo (Fase 3) persistindo source_docs + source_doc_versions.
        if ($this->option('persist')) {
            return $this->persist($auth);
        }
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

    /** Roda o pipeline (Fase 3) num fonte real e persiste, para validação. */
    private function persist(GithubAppAuth $auth): int
    {
        $owner = $this->argument('owner');
        $repoName = $this->argument('repo');
        $path = $this->argument('path');
        $branch = (string) $this->option('branch');

        $repo = \App\Models\ClientSourceRepo::where('owner', $owner)->where('repository', $repoName)->where('active', true)->first();
        if (!$repo) {
            $this->error("ClientSourceRepo não encontrado para {$owner}/{$repoName}");
            return self::FAILURE;
        }
        $fetched = $auth->getFileWithSha($owner, $repoName, $branch, $path);
        if ($fetched === null) {
            $this->error('Não consegui ler o fonte.');
            return self::FAILURE;
        }
        $newCode = $fetched['content'];
        $sha = $auth->getBranchHeadSha($owner, $repoName, $branch);

        $ver = app(\App\SourceCode\SourceDocPipeline::class)->processFile([
            'customer_id' => $repo->customer_id, 'source_repo_id' => $repo->id,
            'owner' => $owner, 'repository' => $repoName, 'branch' => $branch, 'path' => $path,
            'tipo' => $repo->tipo, 'new_code' => $newCode, 'old_code' => null,
            'source_commit_sha' => $sha, 'source_blob_sha' => $fetched['blob_sha'] ?? null, 'parent_source_commit_sha' => null,
            'gmud_id' => null, 'ticket_number' => 'TESTE-F3', 'responsible_user_id' => null, 'responsavel' => 'Validação Fase 3',
        ], $this->option('semantic'));

        $doc = $ver->doc;
        $this->info(json_encode([
            'source_doc_id' => $doc->id, 'version_id' => $ver->id,
            'doc_status' => $doc->analysis_status, 'version_status' => $ver->analysis_status,
            'current_version_id' => $doc->current_version_id, 'current_source_sha' => $doc->current_source_sha,
            'has_deterministic' => !empty($ver->deterministic_json), 'has_semantic' => !empty($ver->semantic_json),
            'has_documentation_json' => !empty($doc->documentation_json),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
