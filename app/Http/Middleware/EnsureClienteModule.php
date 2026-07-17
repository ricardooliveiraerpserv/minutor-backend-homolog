<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforcement REAL (backend) do acesso a módulos por usuário CLIENTE.
 *
 * Segunda camada da regra "cliente escolhe Projetos e/ou Help Desk": o menu do
 * FE esconde, mas quem barra de verdade é isto — senão bastava chamar a API na mão.
 *
 * Age SOMENTE quando o usuário é cliente E a rota pertence a um dos módulos
 * recortáveis, decidido pelo PREFIXO do path (as rotas de portal são intercaladas
 * com rotas de admin, então gate por path evita reestruturar o routes/api.php):
 *   - help-desk/portal*  -> exige 'help_desk'
 *   - client/*           -> exige 'projetos'
 * Cliente com allowed_modules NULL (legado) passa em ambos. Demais perfis: no-op.
 */
class EnsureClienteModule
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && method_exists($user, 'isCliente') && $user->isCliente()) {
            $path = ltrim($request->path(), '/'); // ex.: 'api/v1/client/portal' ou 'client/portal'
            $required = $this->moduleForPath($path);

            if ($required !== null && !$user->canAccessClientModule($required)) {
                return response()->json([
                    'message' => 'Acesso a este módulo não está habilitado para o seu usuário.',
                ], 403);
            }
        }

        return $next($request);
    }

    private function moduleForPath(string $path): ?string
    {
        // Normaliza removendo prefixo de versão (api/v1/) se presente.
        $p = preg_replace('#^api/v\d+/#', '', $path);

        if (str_starts_with($p, 'help-desk/portal')) {
            return 'help_desk';
        }
        if (str_starts_with($p, 'client/')) {
            return 'projetos';
        }
        return null;
    }
}
