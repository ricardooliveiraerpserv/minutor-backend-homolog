<?php

namespace App\SourceCode\Providers;

use App\SourceCode\Exceptions\SourceIntegrationException;
use App\SourceCode\GithubAppAuth;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Provider OFICIAL da integração de código-fonte: autentica via GitHub App
 * (installation token por organização, resolvido/renovado automaticamente pelo
 * {@see GithubAppAuth}). SOMENTE-LEITURA — herda os GETs da base; sem escrita, sem fallback.
 * O restante do sistema não sabe que existe JWT/installation.
 */
class GithubAppProvider extends AbstractGithubReadProvider
{
    public function __construct(private GithubAppAuth $auth)
    {
        parent::__construct();
    }

    public function isConfigured(): bool
    {
        return $this->auth->isConfigured();
    }

    protected function authorizedRequest(string $owner): PendingRequest
    {
        if (!$this->auth->isConfigured()) {
            throw SourceIntegrationException::appNotConfigured();
        }
        // Resolve a instalação do owner e obtém um token temporário (cacheado). Pode lançar APP_NOT_INSTALLED.
        $token = $this->auth->installationToken($owner);
        return Http::withToken($token)
            ->timeout($this->timeout)
            ->withHeaders([
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'Minutor-SourceCode',
            ]);
    }

    /** 404 no repo com a App instalada = distinguir "sem acesso" de "inexistente" (item 14). */
    protected function onRepoNotFound(string $owner, string $repo): void
    {
        if (!$this->auth->installationHasRepo($owner, $repo)) {
            throw SourceIntegrationException::repoNotAuthorized("{$owner}/{$repo}");
        }
        throw SourceIntegrationException::repoNotFound("{$owner}/{$repo}");
    }
}
