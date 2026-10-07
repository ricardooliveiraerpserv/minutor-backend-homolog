<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Visualização inline (?view=1) × download de anexos.
 *  - PDF / imagem / texto          → inline direto.
 *  - Office (doc/xls/ppt/odt/rtf)  → converte p/ PDF via Gotenberg (cache local) e abre inline.
 *  - Demais tipos                  → cai no download.
 *
 * respond() cobre anexos no disco 'public' (local). Para anexos em outro provider
 * (ex.: Supabase via AttachmentService/StorageProvider), o chamador usa
 * isInline()/isOffice()/officeToPdf()/pdfResponse()/inlineDisposition() lendo os
 * bytes pelo próprio provider.
 */
class AttachmentPreviewResponder
{
    private const INLINE = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'txt', 'csv', 'log', 'json', 'xml'];
    private const OFFICE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];

    private function ext(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    public function isInline(string $name): bool
    {
        return in_array($this->ext($name), self::INLINE, true);
    }

    public function isOffice(string $name): bool
    {
        return in_array($this->ext($name), self::OFFICE, true);
    }

    public function inlineDisposition(string $name): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'arquivo';
        return HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name, $fallback);
    }

    /** Caminho para anexos no disco 'public' (local): req-messages, project-comments. */
    public function respond(string $storagePath, string $originalName, ?string $mime, bool $view): Response
    {
        $disk = Storage::disk('public');
        abort_unless($disk->exists($storagePath), 404, 'Arquivo não encontrado.');

        if (! $view) {
            return $disk->download($storagePath, $originalName);
        }
        if ($this->isInline($originalName)) {
            return $disk->response($storagePath, $originalName, ['Content-Disposition' => $this->inlineDisposition($originalName)]);
        }
        if ($this->isOffice($originalName)) {
            $pdf = $this->officeToPdf($disk->get($storagePath), $originalName);
            if ($pdf !== null) {
                return $this->pdfResponse($pdf, $originalName);
            }
        }
        return $disk->download($storagePath, $originalName);
    }

    /** Resposta inline de um PDF (já convertido). */
    public function pdfResponse(string $pdfBytes, string $originalName): Response
    {
        $pdfName = pathinfo($originalName, PATHINFO_FILENAME) . '.pdf';
        return response($pdfBytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => $this->inlineDisposition($pdfName),
        ]);
    }

    /** Converte bytes Office→PDF via Gotenberg, com cache local por conteúdo. Retorna bytes do PDF ou null. */
    public function officeToPdf(string $bytes, string $originalName): ?string
    {
        $disk = Storage::disk('public');
        $cacheKey = 'previews/' . sha1($bytes) . '.pdf';
        if ($disk->exists($cacheKey)) {
            return $disk->get($cacheKey);
        }

        try {
            $url = rtrim((string) config('documents.gotenberg.url'), '/') . '/forms/libreoffice/convert';
            $res = Http::timeout((int) config('documents.gotenberg.timeout', 60))
                ->attach('files', $bytes, $originalName)
                ->post($url);

            if (! $res->successful()) {
                return null;
            }
            $disk->put($cacheKey, $res->body());
            return $res->body();
        } catch (\Throwable $e) {
            Log::warning('AttachmentPreviewResponder: falha ao converter p/ PDF', ['arquivo' => $originalName, 'erro' => $e->getMessage()]);
            return null;
        }
    }
}
