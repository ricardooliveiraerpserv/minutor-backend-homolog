<?php

namespace App\Multitenancy;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

/**
 * Resolve o tenant por:
 *  1) header `X-Tenant` (o FE injeta a partir do hostname — robusto atrás do BFF do Vercel);
 *  2) fallback: subdomínio `<slug>.minutor.com.br` (exceto app/api/www).
 * Sem tenant → null → fica no schema `public` (grupo ERPSERV/BIZIFY). Nenhum impacto no app.minutor.
 */
class HeaderTenantFinder extends TenantFinder
{
    public function findForRequest(Request $request): ?IsTenant
    {
        $slug = trim((string) $request->header('X-Tenant'));

        if ($slug === '') {
            $host = strtolower($request->getHost());
            if (preg_match('/^([a-z0-9-]+)\.minutor\.com\.br$/', $host, $m)
                && ! in_array($m[1], ['app', 'api', 'www'], true)) {
                $slug = $m[1];
            }
        }

        if ($slug === '') {
            return null;
        }

        return Tenant::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->first();
    }
}
