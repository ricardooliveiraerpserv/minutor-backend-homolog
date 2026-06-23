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

        // PDFs servidos para EXIBIÇÃO em iframe (mesma origem): Portal de Propostas (/p/{token}/pdf).
        // Precisam ser "frameáveis" pela própria origem — senão o navegador bloqueia (ícone quebrado).
        $framableInline = $request->is('api/v1/p/*/pdf') || $request->is('api/v1/p/*/deck-html');

        // Headers de segurança
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', $framableInline ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Content-Security-Policy', $framableInline ? "frame-ancestors 'self'" : "default-src 'none'; frame-ancestors 'none'");
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