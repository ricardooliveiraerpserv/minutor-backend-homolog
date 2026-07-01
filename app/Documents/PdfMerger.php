<?php

namespace App\Documents;

use Illuminate\Support\Facades\Http;

/**
 * Mescla PDFs num único arquivo via Gotenberg (/forms/pdfengines/merge).
 * O Gotenberg ordena pela ORDEM ALFABÉTICA do nome — por isso usamos prefixos numéricos (1-, 2-, 3-).
 */
class PdfMerger
{
    /** @param array<string,string> $namedPdfs filename(ordenável) => bytes do PDF. Retorna o PDF mesclado. */
    public static function merge(array $namedPdfs): string
    {
        $namedPdfs = array_filter($namedPdfs, fn ($b) => filled($b));
        if (count($namedPdfs) <= 1) return (string) (array_values($namedPdfs)[0] ?? '');

        $base    = rtrim((string) config('documents.gotenberg.url'), '/');
        $timeout = (int) config('documents.gotenberg.timeout', 60);

        $req = Http::timeout($timeout);
        ksort($namedPdfs); // garante a ordem desejada (1-..., 2-..., 3-...)
        foreach ($namedPdfs as $name => $bytes) {
            $req = $req->attach('files', $bytes, $name);
        }
        $resp = $req->post($base . '/forms/pdfengines/merge');
        if (!$resp->successful()) {
            throw new \RuntimeException("Gotenberg merge falhou ({$resp->status()}): " . substr($resp->body(), 0, 300));
        }
        return $resp->body();
    }
}
