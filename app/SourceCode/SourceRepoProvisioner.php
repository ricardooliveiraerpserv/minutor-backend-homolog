<?php

namespace App\SourceCode;

use App\Models\ClientSourceRepo;
use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * Provisiona automaticamente o repositório de código-fonte de um cliente:
 * cria (ou reaproveita) um repo PRIVADO na organização e registra o vínculo
 * (ClientSourceRepo). Idempotente e best-effort — quem chama nunca deve quebrar
 * por causa disso. Requer a GitHub App com "Administration: Read and write".
 */
class SourceRepoProvisioner
{
    public function __construct(private GithubAppAuth $auth)
    {
    }

    /** Provisionamento ligado? (config + App configurada). */
    public function enabled(): bool
    {
        return (bool) config('services.github_source.auto_provision', true) && $this->auth->isConfigured();
    }

    /** Organização onde os repositórios de cliente vivem. */
    public function owner(): string
    {
        return (string) config('services.github_source.default_owner', 'erpserv-clientes');
    }

    /** true = cliente ATIVO e "real" (não lead/prospect) — deve ter repositório. */
    public function shouldProvision(Customer $customer): bool
    {
        if (!$customer->active) {
            return false;
        }
        // crm_status pode não existir (prod) → getAttribute retorna null → não é lead/prospect.
        return !in_array($customer->getAttribute('crm_status'), ['lead', 'prospect'], true);
    }

    /**
     * Nome do repositório: cliente-<slug-do-nome>. Se OUTRO cliente já reservou esse nome,
     * desambigua com o id (cliente-<slug>-<id>).
     */
    public function repoName(Customer $customer): string
    {
        $slug = Str::slug((string) $customer->name);
        if ($slug === '') {
            $slug = (string) $customer->id;
        }
        $base = 'cliente-' . $slug;
        $taken = ClientSourceRepo::where('owner', $this->owner())
            ->where('repository', $base)
            ->where('customer_id', '!=', $customer->id)
            ->exists();
        return $taken ? "{$base}-{$customer->id}" : $base;
    }

    /**
     * Garante o repositório + vínculo do cliente. Idempotente: se já houver vínculo ativo,
     * retorna-o sem tocar no GitHub. Pode lançar SourceIntegrationException (ex.: sem
     * permissão de escrita) — trate no chamador quando o fluxo não puder falhar.
     */
    public function provisionFor(Customer $customer): ?ClientSourceRepo
    {
        if (!$this->enabled()) {
            return null;
        }
        $existing = ClientSourceRepo::where('customer_id', $customer->id)->where('active', true)->first();
        if ($existing) {
            return $existing;
        }

        $owner = $this->owner();
        $repo = $this->auth->createOrgRepo(
            $owner,
            $this->repoName($customer),
            "Código-fonte do cliente {$customer->name} (Minutor)."
        );

        return ClientSourceRepo::create([
            'customer_id' => $customer->id,
            'owner'       => $owner,
            'repository'  => $repo['name'],
            'branch'      => $repo['default_branch'] ?: 'main',
            'base_path'   => '',
            'tipo'        => 'outros',
            'descricao'   => 'Repositório provisionado automaticamente.',
            'active'      => true,
        ]);
    }
}
