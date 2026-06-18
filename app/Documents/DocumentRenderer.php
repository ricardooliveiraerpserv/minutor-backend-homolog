<?php

namespace App\Documents;

use App\Documents\Contracts\RenderDriver;
use App\Documents\Renderers\ChromiumGotenbergDriver;
use App\Documents\Renderers\DompdfDriver;
use Illuminate\Support\Facades\View;

/**
 * Abstração definitiva de render da Plataforma de Documentos.
 * Consumidores chamam render(template, data, renderer) — sem saber qual motor roda.
 */
class DocumentRenderer
{
    /** @return string PDF bytes */
    public function render(string $template, array $data = [], ?string $renderer = null, array $opts = []): string
    {
        $renderer = $renderer ?: (string) config('documents.default_renderer', 'chromium');
        $html = View::make($template, $data)->render();
        return $this->driver($renderer)->render($html, $opts);
    }

    public function driver(string $name): RenderDriver
    {
        return match ($name) {
            'chromium' => new ChromiumGotenbergDriver(),
            'dompdf'   => new DompdfDriver(),
            default    => throw new \InvalidArgumentException("Renderer desconhecido: {$name}"),
        };
    }
}
