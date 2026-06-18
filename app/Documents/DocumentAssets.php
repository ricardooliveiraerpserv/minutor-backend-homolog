<?php

namespace App\Documents;

/**
 * Embute assets como data-URI (Gotenberg/Chromium não acessa file:// do host).
 * Cache em memória por request. Assets em resources/documents-assets/.
 */
class DocumentAssets
{
    private static array $cache = [];

    public static function dataUri(string $relative): string
    {
        if (isset(self::$cache[$relative])) {
            return self::$cache[$relative];
        }
        // Biblioteca visual oficial das propostas (Fase 1.3).
        $path = resource_path('assets/propostas/' . $relative);
        if (!is_file($path)) {
            $path = resource_path('documents-assets/' . $relative); // fallback legado
        }
        if (!is_file($path)) {
            return self::$cache[$relative] = '';
        }
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            'ttf'  => 'font/ttf',
            'otf'  => 'font/otf',
            'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };
        return self::$cache[$relative] = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }
}
