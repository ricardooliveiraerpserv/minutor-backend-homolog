<?php

namespace App\SourceCode\Contracts;

/**
 * Provedor de código-fonte (hoje GitHub via PAT; futuro GitHub App), SOMENTE-LEITURA.
 * Trocar o provedor não deve alterar o restante do fluxo (GitHubSourceService/Resolver).
 * Nenhum método pode executar operação de escrita.
 */
interface SourceProvider
{
    /** true se há credencial configurada (ex.: GITHUB_SOURCE_TOKEN presente). */
    public function isConfigured(): bool;

    /**
     * Lista os arquivos (paths relativos ao repo) sob $basePath na $branch.
     * Retorna ['path' => string, 'name' => string, 'truncated' => bool].
     */
    public function tree(string $owner, string $repo, string $branch, string $basePath = ''): array;

    /**
     * Último commit que ALTEROU o arquivo na $branch, ou null se não houver.
     * Retorna ['sha','date','author','message'].
     */
    public function lastCommit(string $owner, string $repo, string $branch, string $path): ?array;

    /** Conteúdo bruto do arquivo no $ref (branch OU sha). */
    public function content(string $owner, string $repo, string $ref, string $path): string;

    /**
     * Metadados p/ "Testar acesso": ['default_branch','private','branch_found','file_count','base_path_found'].
     */
    public function inspect(string $owner, string $repo, string $branch, string $basePath = ''): array;
}
