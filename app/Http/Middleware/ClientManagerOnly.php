<?php

namespace App\Http\Middleware;

use App\Support\ClientAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telas "da empresa" (Home/resumo executivo, centros de custo, dashboards de contrato):
 * para o perfil CLIENTE, só o GESTOR do cliente (is_customer_manager) acessa. Convidado
 * (não-gestor) recebe 403. Usuários internos passam direto (regras próprias nos controllers).
 */
class ClientManagerOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (ClientAccess::isNonManagerClient($user)) {
            return response()->json([
                'message' => ClientAccess::MANAGER_ONLY_MESSAGE,
                'code'    => 'client_manager_only',
            ], 403);
        }
        return $next($request);
    }
}
