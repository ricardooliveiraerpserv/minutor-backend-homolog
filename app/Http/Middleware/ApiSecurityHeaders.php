<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Headers de segurança
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // CSP: por padrão bloqueia tudo ('none'). As páginas HTML de aceite/recusa (abertas no
        // NAVEGADOR do cliente, via link do e-mail) precisam de estilos inline, imagens data: (logo
        // + prints colados), script inline (editor de recusa) e enviar o form → CSP relaxada só nelas.
        $htmlPages = ['hd.accept', 'hd.accept.do', 'hd.reject.do'];
        if (in_array(optional($request->route())->getName(), $htmlPages, true)) {
            $response->headers->set('Content-Security-Policy',
                "default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        } else {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        // HSTS apenas em produção sob HTTPS — evita travar dev local em HTTP
        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Headers para APIs
        $response->headers->set('X-API-Version', '1.0.0');

        // Remove headers que podem vazar informações do servidor
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
} 