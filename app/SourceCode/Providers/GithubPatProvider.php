<?php

namespace App\SourceCode\Providers;

use App\SourceCode\Contracts\SourceProvider;
use App\SourceCode\Exceptions\SourceIntegrationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Provedor GitHub via PAT (classic/fine-grained) — SOMENTE-LEITURA (apenas GET).
 * Nenhum método executa escrita (sem commit/push/branch/delete). O token vem de
 * config('services.github_source.token'); ausente => isConfigured()=false (fail-safe).
 */
class GithubPatProvider implements SourceProvider
{
    private ?string $token;
    private string $api;
    private int $timeout;

    public function __construct()
    {
        $this->token = config('services.github_source.token') ?: null;
        $this->api = rtrim((string) config('services.github_source.api', 'https://api.github.com'), '/');
        $this->timeout = (int) config('services.github_source.timeout', 20);
    }

    public function isConfigured(): bool
    {
        return !empty($this->token);
    }

    public function tree(string $owner, string $repo, string $branch, string $basePath = ''): array
    {
        $res = $this->get("/repos/{$owner}/{$repo}/git/trees/" . rawurlencode($branch), ['recursive' => 1]);
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
        $res = $this->get("/repos/{$owner}/{$repo}/commits", ['path' => $path, 'sha' => $branch, 'per_page' => 1]);
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
        // Accept "raw" devolve o conteúdo bruto (não base64) — mais simples e sem limite de 1MB do contents API.
        $res = $this->request()
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
        $repoRes = $this->get("/repos/{$owner}/{$repo}");
        if ($repoRes->status() === 404) {
            throw SourceIntegrationException::repoNotFound("{$owner}/{$repo}");
        }
        $this->assertOk($repoRes);

        $branchRes = $this->get("/repos/{$owner}/{$repo}/branches/" . rawurlencode($branch));
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

    // ── infra HTTP (só GET) ──────────────────────────────────────────────

    private function request()
    {
        if (!$this->isConfigured()) {
            throw SourceIntegrationException::notConfigured();
        }
        return Http::withToken($this->token)
            ->timeout($this->timeout)
            ->withHeaders([
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'Minutor-SourceCode',
            ]);
    }

    private function get(string $path, array $query = []): Response
    {
        return $this->request()->get("{$this->api}{$path}", $query);
    }

    private function assertOk(Response $res): void
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
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }
}
