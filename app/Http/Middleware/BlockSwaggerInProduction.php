<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * M6 (segurança): a documentação da API (Swagger/l5-swagger) NÃO deve ficar acessível
 * em produção — expõe a superfície da API a qualquer um. Em produção retorna 404;
 * em dev/homolog fica disponível normalmente.
 */
class BlockSwaggerInProduction
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('production')) {
            abort(404);
        }
        return $next($request);
    }
}
