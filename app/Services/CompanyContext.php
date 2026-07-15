<?php

namespace App\Services;

/**
 * Empresa ATIVA do request (multi-empresa). Resolve do request atual (header
 * X-Company-ID validado → users.current_company_id). Um override explícito
 * (middleware) tem prioridade. TUDO é atrelado ao REQUEST atual (spl_object_id):
 * se o container for reusado entre requests (testes/worker/octane), o contexto
 * do request anterior NÃO vaza — re-resolve pro request novo.
 *
 * O global scope BelongsToCompany (fase 2) lê `id()` daqui.
 */
class CompanyContext
{
    private ?int $override = null;
    private int $overrideForRequest = -1;
    private int $resolvedForRequest = -1;
    private ?int $resolvedId = null;

    private function currentRequestId(): int
    {
        $req = request();
        return $req ? spl_object_id($req) : 0;
    }

    /** Define a empresa ativa explicitamente (já validada) — vale só p/ o request atual. */
    public function set(?int $companyId): void
    {
        $this->override = $companyId;
        $this->overrideForRequest = $this->currentRequestId();
    }

    /** ID da empresa ativa (override do request → header/current_company_id → null). */
    public function id(): ?int
    {
        $rid = $this->currentRequestId();

        if ($this->override !== null && $this->overrideForRequest === $rid) {
            return $this->override;
        }
        if ($this->resolvedForRequest !== $rid) {
            $this->resolvedForRequest = $rid;
            $this->resolvedId = $this->resolveFromRequest();
        }
        return $this->resolvedId;
    }

    /**
     * Resolve a empresa ativa direto do request (user + header X-Company-ID validado)
     * — não depende da ordem do middleware, então já vale no route-model binding.
     */
    private function resolveFromRequest(): ?int
    {
        $req = request();
        $user = $req?->user();
        if (!$user) {
            return null;
        }
        $header = $req->header('X-Company-ID');
        if ($header !== null && is_numeric($header) && (int) $header > 0 && $user->belongsToCompany((int) $header)) {
            return (int) $header;
        }
        return $user->current_company_id;
    }

    public function has(): bool
    {
        return $this->id() !== null;
    }

    /** Zera o cache de resolução (ex.: após trocar empresa no mesmo request). */
    public function forget(): void
    {
        $this->override = null;
        $this->overrideForRequest = -1;
        $this->resolvedForRequest = -1;
        $this->resolvedId = null;
    }
}
