<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Responde um anexo para DOWNLOAD (força salvar) ou VISUALIZAÇÃO inline (?view=1).
 *  - PDF / imagem / texto          → inline direto.
 *  - Office (doc/xls/ppt/odt/rtf)  → converte p/ PDF via Gotenberg (com cache) e abre inline.
 *  - Demais tipos sem preview      → cai no download (degrada com elegância).
 */
class AttachmentPreviewResponder
{
    private const INLINE = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'txt', 'csv', 'log', 'json', 'xml'];
    private const OFFICE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];

    public function respond(string $storagePath, string $originalName, ?string $mime, bool $view): StreamedResponse
    {
        $disk = Storage::disk('public');
        abort_unless($disk->exists($storagePath), 404, 'Arquivo não encontrado.');

        if (! $view) {
            return $disk->download($storagePath, $originalName);
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (in_array($ext, self::INLINE, true)) {
            return $disk->response($storagePath, $originalName, ['Content-Disposition' => $this->inline($originalName)]);
        }

        if (in_array($ext, self::OFFICE, true)) {
            $pdf = $this->toPdf($storagePath, $originalName);
            if ($pdf !== null) {
                $pdfName = pathinfo($originalName, PATHINFO_FILENAME) . '.pdf';
                return $disk->response($pdf, $pdfName, ['Content-Disposition' => $this->inline($pdfName)]);
            }
        }

        return $disk->download($storagePath, $originalName);
    }

    private function inline(string $name): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'arquivo';
        return HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name, $fallback);
    }

    private function toPdf(string $storagePath, string $originalName): ?string
    {
        $disk = Storage::disk('public');
        $cacheKey = 'previews/' . sha1($storagePath . '|' . $disk->lastModified($storagePath)) . '.pdf';
        if ($disk->exists($cacheKey)) {
            return $cacheKey;
        }

        try {
            $url = rtrim((string) config('documents.gotenberg.url'), '/') . '/forms/libreoffice/convert';
            $res = Http::timeout((int) config('documents.gotenberg.timeout', 60))
                ->attach('files', $disk->get($storagePath), $originalName)
                ->post($url);

            if (! $res->successful()) {
                return null;
            }
            $disk->put($cacheKey, $res->body());
            return $cacheKey;
        } catch (\Throwable $e) {
            Log::warning('AttachmentPreviewResponder: falha ao converter p/ PDF', ['path' => $storagePath, 'erro' => $e->getMessage()]);
            return null;
        }
    }
}
