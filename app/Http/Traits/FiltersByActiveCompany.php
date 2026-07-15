<?php

namespace App\Http\Traits;

use App\Services\CompanyContext;

/**
 * Helper p/ queries CRUAS (DB::table / joins) que o global scope do Eloquent NÃO
 * alcança. Só filtra quando a flag multi-empresa está ligada E há empresa ativa
 * (fora de request/console → null → não filtra). Aplicar em agregações amplas que
 * cruzam tabelas transacionais e são vistas por usuário.
 *
 * Uso: ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('projects.company_id', $cid))
 */
trait FiltersByActiveCompany
{
    protected function activeCompanyId(): ?int
    {
        if (!config('multiempresa.scoping_enabled')) {
            return null;
        }
        return app(CompanyContext::class)->id();
    }
}
