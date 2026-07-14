<?php

namespace App\Models\Scopes;

use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Isola automaticamente as queries por empresa ATIVA. Só age quando:
 *  - a flag `multiempresa.scoping_enabled` está ligada, E
 *  - há empresa ativa no request (CompanyContext::id()).
 * Fora de request (jobs/console/scheduler) não há empresa ativa → NÃO filtra
 * (evita esconder dados de background). `withoutCompanyScope()` remove o filtro
 * pontualmente (ex.: relatórios cross-empresa como Fechamento Diretoria).
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!config('multiempresa.scoping_enabled')) {
            return;
        }
        $companyId = app(CompanyContext::class)->id();
        if ($companyId === null) {
            return;
        }
        $builder->where($model->getTable() . '.company_id', $companyId);
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutCompanyScope', fn (Builder $b) => $b->withoutGlobalScope(static::class));
    }
}
