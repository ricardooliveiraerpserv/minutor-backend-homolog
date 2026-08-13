<?php

namespace App\SourceCode\Exceptions;

use RuntimeException;

/**
 * Erro da integração de código-fonte, com código operacional para a UI/logs.
 * NUNCA carrega token/segredo na mensagem.
 */
class SourceIntegrationException extends RuntimeException
{
    public string $errorCode;
    public int $httpStatus;

    public function __construct(string $errorCode, string $message, int $httpStatus = 502)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public static function notConfigured(): self
    {
        return new self('NOT_CONFIGURED', 'Integração de código-fonte não configurada (GITHUB_SOURCE_TOKEN ausente no servidor).', 503);
    }

    public static function repoNotFound(string $full): self
    {
        return new self('REPO_NOT_FOUND', "Repositório não encontrado ou sem acesso: {$full}.", 404);
    }

    public static function branchNotFound(string $branch): self
    {
        return new self('BRANCH_NOT_FOUND', "Branch \"{$branch}\" não encontrada no repositório.", 404);
    }

    public static function pathNotFound(string $path): self
    {
        return new self('PATH_NOT_FOUND', "Caminho não encontrado na branch: \"{$path}\".", 404);
    }

    public static function rateLimited(): self
    {
        return new self('RATE_LIMITED', 'Limite de requisições do GitHub atingido. Tente novamente em instantes.', 429);
    }

    public static function upstream(int $status): self
    {
        return new self('UPSTREAM', "Falha ao consultar o GitHub (HTTP {$status}).", 502);
    }
}
