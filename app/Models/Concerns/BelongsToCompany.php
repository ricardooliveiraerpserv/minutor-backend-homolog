<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model pertence a uma empresa. Aplica o CompanyScope (isolamento por empresa
 * ativa) e carimba company_id ao criar. Tudo GATED por
 * config('multiempresa.scoping_enabled') — inerte enquanto desligado.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model) {
            if (!config('multiempresa.scoping_enabled')) {
                return;
            }
            if (empty($model->company_id)) {
                $companyId = app(CompanyContext::class)->id();
                if ($companyId) {
                    $model->company_id = $companyId;
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
