<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermissionOrAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Não autenticado'
            ], 401);
        }

        // Administradores têm acesso total
        if ($user->hasRole('Administrator')) {
            return $next($request);
        }

        // Verificar se tem a permissão específica
        if ($user->can($permission)) {
            return $next($request);
        }

        // Se não tem nem role de admin nem a permissão específica
        return response()->json([
            'success' => false,
            'message' => "Acesso negado. Você precisa da permissão '{$permission}' ou ser um Administrador para acessar este recurso.",
            'required_permission' => $permission,
            'user_permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            'user_roles' => $user->getRoleNames()->toArray()
        ], 403);
    }
}
