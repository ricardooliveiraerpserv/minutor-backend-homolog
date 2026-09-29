<?php

namespace App\SourceCode\Providers;

use App\SourceCode\Contracts\SourceProvider;
use App\SourceCode\Exceptions\SourceIntegrationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Lógica de negócio (SOMENTE-LEITURA) do GitHub, AGNÓSTICA de autenticação.
 * As subclasses só fornecem `authorizedRequest($owner)` (como autenticar) e `isConfigured()`.
 * Nenhum método executa escrita — apenas GET.
 */
abstract class AbstractGithubReadProvider implements SourceProvider
{
    protected string $api;
    protected int $timeout;

    public function __construct()
    {
        $this->api = rtrim((string) config('services.github_source.api', 'https://api.github.com'), '/');
        $this->timeout = (int) config('services.github_source.timeout', 20);
    }

    abstract public function isConfigured(): bool;

    /** Cliente HTTP já autenticado para o `owner` (headers-base + credencial). Lança se não configurado. */
    abstract protected function authorizedRequest(string $owner): PendingRequest;

    /** Repos que a credencial enxerga no owner (p/ o seletor). */
    abstract public function availableRepositories(string $owner): array;

    /** Hook: como classificar um 404 no repo (App distingue "sem acesso" de "inexistente"). */
    protected function onRepoNotFound(string $owner, string $repo): void
    {
        throw SourceIntegrationException::repoNotFound("{$owner}/{$repo}");
    }

    public function tree(string $owner, string $repo, string $branch, string $basePath = ''): array
    {
        $res = $this->authorizedRequest($owner)->get("{$this->api}/repos/{$owner}/{$repo}/git/trees/" . rawurlencode($branch), ['recursive' => 1]);
        if ($res->status() === 404) {
            throw SourceIntegrationException::branchNotFound($branch);
        }
        $this->assertOk($res);
        $base = trim($basePath, '/');
        $prefix = $base === '' ? '' : $base . '/';
        $files = [];
        foreach ($res->json('tree', []) as $node) {
            if (($node['type'] ?? '') !== 'blob') {
                continue;
            }
            $path = (string) ($node['path'] ?? '');
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }
            $files[] = ['path' => $path, 'name' => basename($path)];
        }
        return ['files' => $files, 'truncated' => (bool) $res->json('truncated', false)];
    }

    public function lastCommit(string $owner, string $repo, string $branch, string $path): ?array
    {
        $res = $this->authorizedRequest($owner)->get("{$this->api}/repos/{$owner}/{$repo}/commits", ['path' => $path, 'sha' => $branch, 'per_page' => 1]);
        if ($res->status() === 404) {
            return null;
        }
        $this->assertOk($res);
        $c = $res->json('0');
        if (!$c) {
            return null;
        }
        return [
            'sha'     => $c['sha'] ?? null,
            'date'    => data_get($c, 'commit.author.date') ?? data_get($c, 'commit.committer.date'),
            'author'  => data_get($c, 'commit.author.name'),
            'message' => data_get($c, 'commit.message'),
        ];
    }

    public function content(string $owner, string $repo, string $ref, string $path): string
    {
        $res = $this->authorizedRequest($owner)
            ->withHeaders(['Accept' => 'application/vnd.github.raw+json'])
            ->get("{$this->api}/repos/{$owner}/{$repo}/contents/" . $this->encodePath($path), ['ref' => $ref]);
        if ($res->status() === 404) {
            throw SourceIntegrationException::pathNotFound($path);
        }
        $this->assertOk($res);
        return $res->body();
    }

    public function inspect(string $owner, string $repo, string $branch, string $basePath = ''): array
    {
        $repoRes = $this->authorizedRequest($owner)->get("{$this->api}/repos/{$owner}/{$repo}");
        if ($repoRes->status() === 404) {
            $this->onRepoNotFound($owner, $repo);   // App: distingue não-autorizado × inexistente
        }
        $this->assertOk($repoRes);

        $branchRes = $this->authorizedRequest($owner)->get("{$this->api}/repos/{$owner}/{$repo}/branches/" . rawurlencode($branch));
        $branchFound = $branchRes->successful();
        if ($branchRes->status() !== 404) {
            $this->assertOk($branchRes);
        }

        $fileCount = 0;
        $basePathFound = false;
        if ($branchFound) {
            $tree = $this->tree($owner, $repo, $branch, $basePath);
            $fileCount = count($tree['files']);
            $basePathFound = trim($basePath, '/') === '' ? true : $fileCount > 0;
        }

        return [
            'default_branch'  => $repoRes->json('default_branch'),
            'private'         => (bool) $repoRes->json('private', true),
            'branch_found'    => $branchFound,
            'base_path_found' => $basePathFound,
            'file_count'      => $fileCount,
        ];
    }

    protected function assertOk(Response $res): void
    {
        if ($res->successful()) {
            return;
        }
        if ($res->status() === 429 || ($res->status() === 403 && (string) $res->header('X-RateLimit-Remaining') === '0')) {
            throw SourceIntegrationException::rateLimited();
        }
        throw SourceIntegrationException::upstream($res->status());
    }

    /** Preserva as barras de diretório ao codificar o path para a URL. */
    protected function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }
}
