<?php

namespace App\Documents\Renderers;

use App\Documents\Contracts\RenderDriver;
use Illuminate\Support\Facades\Http;

/**
 * Motor OFICIAL da plataforma: Chromium isolado via serviço HTTP (Gotenberg).
 * Backend PHP NÃO embute Node/Chromium — só fala HTTP com o serviço dedicado.
 */
class ChromiumGotenbergDriver implements RenderDriver
{
    public function name(): string
    {
        return 'chromium';
    }

    public function render(string $html, array $opts = []): string
    {
        $base    = rtrim((string) config('documents.gotenberg.url'), '/');
        $timeout = (int) config('documents.gotenberg.timeout', 60);

        $form = [
            'paperWidth'        => (string) ($opts['paperWidth']  ?? 8.27),   // A4 retrato por padrão
            'paperHeight'       => (string) ($opts['paperHeight'] ?? 11.69),
            'marginTop'         => (string) ($opts['marginTop']    ?? 0),
            'marginBottom'      => (string) ($opts['marginBottom'] ?? 0),
            'marginLeft'        => (string) ($opts['marginLeft']   ?? 0),
            'marginRight'       => (string) ($opts['marginRight']  ?? 0),
            'printBackground'   => ($opts['printBackground'] ?? true) ? 'true' : 'false',
            'preferCssPageSize' => ($opts['preferCssPageSize'] ?? false) ? 'true' : 'false',
        ];

        $resp = Http::timeout($timeout)
            ->attach('files', $html, 'index.html')
            ->post($base . '/forms/chromium/convert/html', $form);

        if (!$resp->successful()) {
            throw new \RuntimeException("Gotenberg falhou ({$resp->status()}): " . substr($resp->body(), 0, 300));
        }

        return $resp->body();
    }
}
