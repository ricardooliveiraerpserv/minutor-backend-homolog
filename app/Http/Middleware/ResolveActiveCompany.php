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
        // $request->user() usa o guard que autenticou (sanctum) — ao contrário de
        // auth()->user() (guard web/sessão), que é null em request por token.
        $user = $request->user();

        if ($user) {
            // Default = empresa ativa persistida do usuário.
            $active = $user->current_company_id;

            // Override pontual via header X-Company-ID (só se vinculado).
            $header = $request->header('X-Company-ID');
            if ($header !== null && is_numeric($header) && (int) $header > 0
                && $user->belongsToCompany((int) $header)) {
                $active = (int) $header;
            }

            if ($active) {
                $this->context->set((int) $active);
            }
        }

        return $next($request);
    }
}
