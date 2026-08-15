<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Varre RECURSIVAMENTE os repositórios acessíveis pela GitHub App e lista os maiores fontes
 * AdvPL/TL++ (por tamanho em bytes), via Git Trees API (recursive=1). Só leitura. Serve para
 * escolher um fonte REAL grande/complexo para o gate de qualidade do analyzer (não cria fixture).
 * Resultado (top N) fica em system_settings 'diag_scan'.
 *
 *   php artisan source-doc:scan erpserv-clientes
 *   php artisan source-doc:scan erpserv-clientes --repo=jng --top=40
 */
class SourceDocScanCommand extends Command
{
    protected $signature = 'source-doc:scan {owner} {--repo=} {--top=30}';
    protected $description = 'Varre repos (recursivo) e lista os maiores fontes AdvPL/TL++ para o gate de qualidade.';

    private const EXTS = ['prw', 'prx', 'tlpp', 'tlp', 'prg', 'apl', 'apw', 'aph', 'apu'];

    public function handle(GithubAppAuth $auth): int
    {
        $owner = $this->argument('owner');
        $only = $this->option('repo');
        $top = max(1, (int) $this->option('top'));
        $api = rtrim(config('services.github_source.api', 'https://api.github.com'), '/');
        $tok = $auth->installationToken($owner);
        $h = fn () => Http::withToken($tok)->withHeaders([
            'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'minutor',
        ]);

        $repos = [];
        if ($only) {
            $repos = [$only];
        } else {
            $page = 1;
            while (true) {
                $r = $h()->get("{$api}/installation/repositories", ['per_page' => 100, 'page' => $page]);
                $items = $r->json('repositories') ?? [];
                foreach ($items as $it) {
                    $repos[] = $it['name'];
                }
                if (count($items) < 100) {
                    break;
                }
                $page++;
            }
        }

        $found = [];
        foreach ($repos as $repo) {
            $meta = $h()->get("{$api}/repos/{$owner}/{$repo}");
            $branch = $meta->json('default_branch') ?? 'main';
            $sha = $auth->getBranchHeadSha($owner, $repo, $branch);
            if (!$sha) {
                continue;
            }
            $tree = $h()->get("{$api}/repos/{$owner}/{$repo}/git/trees/{$sha}", ['recursive' => 1]);
            $truncated = (bool) $tree->json('truncated');
            foreach (($tree->json('tree') ?? []) as $node) {
                if (($node['type'] ?? '') !== 'blob') {
                    continue;
                }
                $path = $node['path'] ?? '';
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, self::EXTS, true)) {
                    continue;
                }
                $found[] = ['repo' => $repo, 'branch' => $branch, 'path' => $path, 'size' => (int) ($node['size'] ?? 0)];
            }
            if ($truncated) {
                $this->warn("árvore truncada em {$repo} (repo muito grande) — top pode estar incompleto");
            }
        }

        usort($found, fn ($a, $b) => $b['size'] <=> $a['size']);
        $count = count($found);
        $found = array_slice($found, 0, $top);
        SystemSetting::set('diag_scan', json_encode(['total' => $count, 'top' => $found], JSON_UNESCAPED_UNICODE), 'string', 'diag');

        $this->info("fontes AdvPL/TL++ encontrados: {$count} — top {$top} por tamanho:");
        foreach ($found as $f) {
            $this->line(sprintf('%9d B  %-24s  %s', $f['size'], $f['repo'], $f['path']));
        }
        return self::SUCCESS;
    }
}
