<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate ESTRITO por permissão (data-driven): Admin passa via '*'; QUALQUER outro perfil — inclusive
 * Coordenador — só passa se tiver a permissão exata. Diferente de CheckPermissionOrAdmin, que
 * auto-passa Admin E Coordenador. Use quando a ação NÃO deve liberar coordenador por padrão
 * (ex.: source_docs.reprocess no MVP), mantendo a decisão no PermissionService/Configurador.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
        }
        foreach ($permissions as $permission) {
            if ($user->hasAccess($permission)) { // hasAccess trata '*' (admin); NÃO auto-passa perfil
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => "Acesso negado. Requer a permissão '" . implode(' ou ', $permissions) . "'.",
            'required_permissions' => $permissions,
            'user_type' => $user->type,
        ], 403);
    }
}
