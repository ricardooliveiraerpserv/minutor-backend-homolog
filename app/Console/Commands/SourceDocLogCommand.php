<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diag (read-only) — lista os últimos commits que tocaram um PATH (GitHub commits?path=). Serve
 * para escolher um commit histórico real e validar "documentalmente antigo" (source-doc:analyze
 * --ref=<sha>), sem alterar o Git. Grava em system_settings 'diag_log'.
 *
 *   php artisan source-doc:log erpserv-clientes concreserv RPO_PRODUCAO/Estoque/CCSPCP02.PRW --n=5
 */
class SourceDocLogCommand extends Command
{
    protected $signature = 'source-doc:log {owner} {repo} {path} {--branch=main} {--n=8}';
    protected $description = 'Diag: últimos commits que tocaram um path (para escolher ref histórico).';

    public function handle(GithubAppAuth $auth): int
    {
        $owner = $this->argument('owner');
        $repo = $this->argument('repo');
        $path = $this->argument('path');
        $api = rtrim(config('services.github_source.api', 'https://api.github.com'), '/');
        $tok = $auth->installationToken($owner);
        $res = Http::withToken($tok)->timeout(20)->withHeaders([
            'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Minutor-SourceCode',
        ])->get("{$api}/repos/{$owner}/{$repo}/commits", [
            'path' => $path, 'sha' => (string) $this->option('branch'), 'per_page' => (int) $this->option('n'),
        ]);
        if (!$res->successful()) {
            $this->error("GitHub devolveu {$res->status()}");
            return self::FAILURE;
        }
        $out = [];
        foreach ($res->json() ?? [] as $c) {
            $out[] = [
                'sha'     => $c['sha'] ?? null,
                'date'    => $c['commit']['committer']['date'] ?? ($c['commit']['author']['date'] ?? null),
                'message' => strtok((string) ($c['commit']['message'] ?? ''), "\n"),
            ];
        }
        SystemSetting::set('diag_log', json_encode(['path' => $path, 'commits' => $out], JSON_UNESCAPED_UNICODE), 'string', 'diag');
        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
