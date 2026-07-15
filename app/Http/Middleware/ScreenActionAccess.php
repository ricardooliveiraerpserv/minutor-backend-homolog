<?php

namespace App\Http\Middleware;

use App\Services\AccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforça a política do Configurador (nav_screens) para uma AÇÃO de tela.
 * Uso: ->middleware('screen.action:/users,users.create')
 *
 * ADITIVO: entra por cima do gate legado (permission.or.admin/PermissionService).
 * Só consegue NEGAR (403) — nunca concede acesso. Se o Configurador nega a ação para
 * o perfil (deny_profiles/deny_users) ou o perfil não vê a tela (profiles), devolve 403.
 */
class ScreenActionAccess
{
    public function handle(Request $request, Closure $next, string $screenKey, string $actionKey): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Não autenticado'], 401);
        }

        if (!AccessControl::screenActionAllowed($user, $screenKey, $actionKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado pela configuração de menu (Configurador).',
                'screen'  => $screenKey,
                'action'  => $actionKey,
            ], 403);
        }

        return $next($request);
    }
}
