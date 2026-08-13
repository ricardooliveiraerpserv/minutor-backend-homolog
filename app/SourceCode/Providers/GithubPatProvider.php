<?php

namespace App\SourceCode\Providers;

use App\SourceCode\Exceptions\SourceIntegrationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * ⚠️ LEGADO / NÃO-OFICIAL. Provider por PAT estático (config `services.github_source.token`).
 * O provider OFICIAL da integração é o {@see GithubAppProvider} (GitHub App). Este NÃO é
 * bindado, NÃO tem fallback e NUNCA deve usar o token de deploy. Mantido só para referência
 * durante a homologação. SOMENTE-LEITURA (herda os GETs da base).
 */
class GithubPatProvider extends AbstractGithubReadProvider
{
    private ?string $token;

    public function __construct()
    {
        parent::__construct();
        $this->token = config('services.github_source.token') ?: null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->token);
    }

    protected function authorizedRequest(string $owner): PendingRequest
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
}
