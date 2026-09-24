<?php

namespace App\SourceCode;

use App\Models\ClientSourceRepo;
use App\Models\Customer;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Segurança da cadeia cliente → repositório autorizado → path. O frontend NUNCA fornece
 * owner/repo/branch livremente: tudo é re-resolvido a partir do customer_id contra as
 * fontes ATIVAS cadastradas. Rejeita path fora do base_path e travessia de diretório.
 */
class SourceRepoResolver
{
    /** Repos ATIVOS autorizados do cliente. */
    public function authorizedRepos(Customer $customer)
    {
        return $customer->sourceRepos()->where('active', true)->get();
    }

    /**
     * Valida owner/repo/path contra as fontes ativas do cliente e devolve o ClientSourceRepo.
     * Aborta 403 se o repo não pertencer ao cliente OU o path escapar do base_path.
     */
    public function assertAuthorized(Customer $customer, string $owner, string $repository, string $path): ClientSourceRepo
    {
        $repo = $customer->sourceRepos()
            ->where('active', true)
            ->where('owner', $owner)
            ->where('repository', $repository)
            ->first();

        if (!$repo) {
            throw new HttpException(403, 'Repositório não autorizado para este cliente.');
        }

        $clean = trim(str_replace('\\', '/', $path), '/');
        // Sem travessia de diretório em nenhum segmento.
        foreach (explode('/', $clean) as $seg) {
            if ($seg === '..' || $seg === '.') {
                throw new HttpException(403, 'Caminho inválido.');
            }
        }

        $base = $repo->normalizedBasePath();
        if ($base !== '' && !str_starts_with($clean . '/', $base . '/')) {
            throw new HttpException(403, 'Caminho fora da pasta base autorizada do cliente.');
        }

        return $repo;
    }
}
