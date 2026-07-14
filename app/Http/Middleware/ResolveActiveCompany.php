<?php

namespace App\Http\Middleware;

use App\Services\CompanyContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Aplica o override de empresa ativa via header `X-Company-ID` (uma requisição
 * pontual noutra empresa sem mudar o default). Só aceita se o usuário for
 * vinculado à empresa — senão ignora e cai no `current_company_id`. Roda no
 * grupo auth:sanctum (já tem $request->user()).
 */
class ResolveActiveCompany
{
    public function __construct(private CompanyContext $context)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $header = $request->header('X-Company-ID');

        if ($user && $header !== null && is_numeric($header)) {
            $companyId = (int) $header;
            if ($companyId > 0 && $user->belongsToCompany($companyId)) {
                $this->context->set($companyId);
            }
        }

        return $next($request);
    }
}
