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

        // Página HTML do popup de step-up do Cofre (callback OAuth): precisa de estilo +
        // script inline. CSP própria (self + inline) em vez de default-src 'none'.
        $vaultPopup = $request->is('api/v1/integrations/microsoft/callback');

        // Páginas de aceite/recusa da solução (cliente pelo e-mail, sem login): HTML branded
        // com estilo inline + logo (data:URI). CSP própria — senão renderiza cru e sem logo.
        $acceptPage = $request->is('api/v1/hd/aceite/*');

        // Headers de segurança
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', $framableInline ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $csp = "default-src 'none'; frame-ancestors 'none'";
        if ($framableInline) {
            $csp = "frame-ancestors 'self'";
        } elseif ($vaultPopup) {
            $csp = "default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' data:";
        } elseif ($acceptPage) {
            // script-src inline: a tela de recusa usa um script inline para COLAR (Ctrl+V) print no
            // anexo e mostrar miniatura. Conteúdo 100% gerado no servidor (sem dado do usuário no
            // contexto de script) → 'unsafe-inline' aqui é seguro, como no popup do Cofre.
            $csp = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; frame-ancestors 'none'";
        }
        $response->headers->set('Content-Security-Policy', $csp);
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