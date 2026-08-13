<?php

namespace App\SourceCode;

use App\Models\ClientSourceRepo;
use App\Models\Customer;
use App\SourceCode\Contracts\SourceProvider;
use App\SourceCode\Exceptions\SourceIntegrationException;
use Illuminate\Support\Facades\Cache;

/**
 * Orquestra a busca/resolução de fontes SOMENTE-LEITURA sobre os repositórios ATIVOS
 * autorizados de um cliente. Índice de nomes/paths cacheado (Redis ~15min); metadados de
 * commit só para os poucos resultados exibidos. Ranking local; nunca seleciona sozinho.
 */
class GitHubSourceService
{
    private const TREE_TTL = 900;     // 15 min
    private const MAX_RESULTS = 8;    // resultados exibidos por busca

    public function __construct(private SourceProvider $provider)
    {
    }

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    private function assertConfigured(): void
    {
        if (!$this->provider->isConfigured()) {
            throw SourceIntegrationException::notConfigured();
        }
    }

    /** Árvore de arquivos de um repo (cacheada). */
    public function treeCached(string $owner, string $repo, string $branch, string $basePath = ''): array
    {
        $this->assertConfigured();
        $key = 'scf:tree:' . sha1("{$owner}/{$repo}@{$branch}:{$basePath}");
        return Cache::remember($key, self::TREE_TTL, fn () => $this->provider->tree($owner, $repo, $branch, $basePath));
    }

    /** Invalida o cache do índice de um repo (usado pelo "Atualizar índice"). */
    public function forgetTree(ClientSourceRepo $repo): void
    {
        Cache::forget('scf:tree:' . sha1("{$repo->owner}/{$repo->repository}@{$repo->branch}:{$repo->normalizedBasePath()}"));
    }

    /**
     * Busca fuzzy nos repos ATIVOS do cliente (fan-out). Ranking: exato > começa > contém >
     * fuzzy; desempate por commit mais recente (só nos exibidos). NÃO seleciona automaticamente.
     */
    public function search(Customer $customer, string $term): array
    {
        $this->assertConfigured();
        $term = trim($term);
        if ($term === '') {
            return ['items' => [], 'truncated' => false];
        }

        $repos = $customer->sourceRepos()->where('active', true)->get();
        $ranked = [];
        $truncated = false;

        foreach ($repos as $repo) {
            $tree = $this->treeCached($repo->owner, $repo->repository, $repo->branch, $repo->normalizedBasePath());
            $truncated = $truncated || ($tree['truncated'] ?? false);
            foreach ($tree['files'] as $f) {
                $score = $this->relevance($f['name'], $term);
                if ($score <= 0) {
                    continue;
                }
                $ranked[] = [
                    'score'      => $score,
                    'repo_id'    => $repo->id,
                    'owner'      => $repo->owner,
                    'repository' => $repo->repository,
                    'branch'     => $repo->branch,
                    'tipo'       => $repo->tipo,
                    'path'       => $f['path'],
                    'name'       => $f['name'],
                ];
            }
        }

        // Ordena por relevância (desc). Empate real será refinado por data após o lastCommit.
        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['path'], $b['path']));
        $top = array_slice($ranked, 0, self::MAX_RESULTS);

        // Metadados de commit só dos exibidos.
        foreach ($top as &$it) {
            $commit = $this->provider->lastCommit($it['owner'], $it['repository'], $it['branch'], $it['path']);
            $it['commit'] = $commit;   // sha/date/author/message (ou null)
            unset($it['score']);
        }
        unset($it);

        // Desempate final: entre exibidos, o mais recentemente alterado sobe.
        usort($top, function ($a, $b) {
            $da = $a['commit']['date'] ?? null;
            $db = $b['commit']['date'] ?? null;
            if ($da === $db) {
                return 0;
            }
            return strcmp((string) $db, (string) $da);
        });

        return ['items' => array_values($top), 'truncated' => $truncated];
    }

    /**
     * Resolve a versão MAIS RECENTE de um arquivo (último commit da branch) + conteúdo.
     * Usado na Fase 1 (anexação). Aqui só a resolução — nada é anexado nesta fase.
     */
    public function resolveLatest(ClientSourceRepo $repo, string $path): array
    {
        $this->assertConfigured();
        $commit = $this->provider->lastCommit($repo->owner, $repo->repository, $repo->branch, $path);
        if (!$commit || empty($commit['sha'])) {
            throw SourceIntegrationException::pathNotFound($path);
        }
        $content = $this->provider->content($repo->owner, $repo->repository, $commit['sha'], $path);
        return ['commit' => $commit, 'content' => $content];
    }

    /** "Testar acesso" — metadados operacionais do repo/branch/base_path. */
    public function testAccess(ClientSourceRepo $repo): array
    {
        $this->assertConfigured();
        return $this->provider->inspect($repo->owner, $repo->repository, $repo->branch, $repo->normalizedBasePath());
    }

    /**
     * Relevância nome × termo. 100 exato · 80 começa · 60 contém (também sem extensão) ·
     * 30..0 fuzzy (subsequência). Case-insensitive.
     */
    private function relevance(string $name, string $term): int
    {
        $n = mb_strtolower($name);
        $t = mb_strtolower($term);
        $stem = mb_strtolower(pathinfo($name, PATHINFO_FILENAME));

        if ($n === $t || $stem === $t) {
            return 100;
        }
        if (str_starts_with($n, $t) || str_starts_with($stem, $t)) {
            return 80;
        }
        if (str_contains($n, $t)) {
            return 60;
        }
        return $this->subsequence($t, $n) ? 30 : 0;
    }

    /** true se $needle é subsequência (em ordem) de $haystack — fuzzy simples. */
    private function subsequence(string $needle, string $haystack): bool
    {
        $i = 0;
        $len = mb_strlen($needle);
        if ($len === 0) {
            return false;
        }
        foreach (mb_str_split($haystack) as $ch) {
            if ($ch === mb_substr($needle, $i, 1)) {
                $i++;
                if ($i >= $len) {
                    return true;
                }
            }
        }
        return false;
    }
}
