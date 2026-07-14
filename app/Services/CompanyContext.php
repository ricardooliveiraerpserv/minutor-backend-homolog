<?php

namespace App\Services;

/**
 * Empresa ATIVA do request (multi-empresa). Resolve preguiçosamente do
 * usuário autenticado (`users.current_company_id`) — evita depender da ordem
 * de middleware. Um override explícito (header X-Company-ID já validado) tem
 * prioridade. Registrado como singleton-por-request (scoped) no container.
 *
 * O global scope BelongsToCompany (fase 2) lê `id()` daqui.
 */
class CompanyContext
{
    private ?int $override = null;
    private bool $resolved = false;
    private ?int $resolvedId = null;

    /** Define a empresa ativa explicitamente (já validada como vínculo do usuário). */
    public function set(?int $companyId): void
    {
        $this->override = $companyId;
    }

    /** ID da empresa ativa (override → current_company_id do usuário → null). */
    public function id(): ?int
    {
        if ($this->override !== null) {
            return $this->override;
        }
        if (!$this->resolved) {
            $this->resolved = true;
            $this->resolvedId = auth()->user()?->current_company_id;
        }
        return $this->resolvedId;
    }

    public function has(): bool
    {
        return $this->id() !== null;
    }

    /** Zera o cache de resolução (ex.: após trocar empresa no mesmo request). */
    public function forget(): void
    {
        $this->override = null;
        $this->resolved = false;
        $this->resolvedId = null;
    }
}
