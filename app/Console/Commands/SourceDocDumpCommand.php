<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Dump dos fontes de um repo (base64 em system_settings 'diag_src') p/ inspeção/documentação. */
class SourceDocDumpCommand extends Command
{
    protected $signature = 'source-doc:dump {owner} {repo} {branch=main}';
    protected $description = 'Lista e baixa os fontes de um repositório (para gerar documentação).';

    public function handle(GithubAppAuth $a): int
    {
        $owner = $this->argument('owner');
        $repo = $this->argument('repo');
        $branch = $this->argument('branch');
        $tok = $a->installationToken($owner);
        $root = Http::withToken($tok)->withHeaders([
            'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'minutor',
        ])->get("https://api.github.com/repos/{$owner}/{$repo}/contents/")->json();
        $names = is_array($root) ? array_map(fn ($x) => $x['path'] ?? '', $root) : [];
        $srcs = [];
        foreach ($names as $p) {
            if (preg_match('/\.(prw|prx|tlpp|prg|ch|th|apl|apw|aph)$/i', $p)) {
                $srcs[$p] = base64_encode((string) $a->getFileContent($owner, $repo, $branch, $p));
            }
        }
        SystemSetting::set('diag_src', json_encode(['names' => $names, 'srcs' => $srcs], JSON_UNESCAPED_UNICODE), 'string', 'diag');
        $this->info('nomes: ' . implode(', ', $names));
        $this->info('fontes baixados: ' . implode(', ', array_keys($srcs)));
        return self::SUCCESS;
    }
}
