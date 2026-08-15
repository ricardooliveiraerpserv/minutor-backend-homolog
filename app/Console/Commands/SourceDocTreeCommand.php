<?php

namespace App\Console\Commands;

use App\Models\SourceDoc;
use App\Models\SystemSetting;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;

/**
 * Diag Bloco 3 — valida o treeBlobShas (anti-N+1) contra o GitHub REAL: resolve a árvore recursiva
 * (path→blob_sha) do HEAD numa ÚNICA chamada e grava uma amostra em system_settings 'diag_tree'
 * (marcando quais paths já têm source_doc). Usado para escolher um fonte ainda NÃO documentado
 * na validação real. Sem expor conteúdo — só paths e SHAs.
 *
 *   php artisan source-doc:tree erpserv-clientes jng main --limit=15
 */
class SourceDocTreeCommand extends Command
{
    protected $signature = 'source-doc:tree {owner} {repo} {branch=main} {--limit=15}';
    protected $description = 'Diag: árvore (path→blob_sha) do HEAD via treeBlobShas (anti-N+1).';

    public function handle(GithubAppAuth $auth): int
    {
        $owner = (string) $this->argument('owner');
        $repo = (string) $this->argument('repo');
        $branch = (string) $this->argument('branch');
        $limit = (int) $this->option('limit');

        $t0 = microtime(true);
        $map = $auth->treeBlobShas($owner, $repo, $branch); // 1 request → repo inteiro
        $ms = (int) round((microtime(true) - $t0) * 1000);

        $documented = SourceDoc::where('owner', $owner)->where('repository', $repo)->where('branch', $branch)
            ->pluck('path')->all();
        $documentedSet = array_flip($documented);

        $sample = [];
        foreach (array_slice($map, 0, $limit, true) as $path => $sha) {
            $sample[] = ['path' => $path, 'blob_sha' => $sha, 'has_doc' => isset($documentedSet[$path])];
        }
        // Um path AINDA NÃO documentado (bom p/ validar ingestão fresca → ATUALIZADA).
        $fresh = null;
        foreach ($map as $path => $sha) {
            if (!isset($documentedSet[$path]) && preg_match('/\.(prw|prx|tlpp|apw|apl|aph|prg|ch|th)$/i', $path)) {
                $fresh = ['path' => $path, 'blob_sha' => $sha];
                break;
            }
        }

        $out = [
            'owner' => $owner, 'repository' => $repo, 'branch' => $branch,
            'total_blobs' => count($map), 'elapsed_ms' => $ms,
            'documented_paths' => $documented,
            'sample' => $sample,
            'fresh_candidate' => $fresh,
        ];
        SystemSetting::set('diag_tree', json_encode($out, JSON_UNESCAPED_UNICODE), 'string', 'diag');
        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
