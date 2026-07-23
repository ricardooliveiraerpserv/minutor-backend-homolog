<?php

namespace App\Attachments\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FASE 11 — Storage provider Supabase Storage (API REST).
 *
 * Usa a API REST nativa do Supabase Storage (não o protocolo S3, que exigiria
 * chaves SigV4). Auth por service_role JWT. Ativado com ATTACHMENTS_DRIVER=supabase.
 * Env: SUPABASE_STORAGE_URL (…/storage/v1), SUPABASE_STORAGE_KEY (JWT),
 * SUPABASE_STORAGE_BUCKET. `storage_path` = key dentro do bucket ("attachments/…").
 */
class SupabaseStorageProvider implements StorageProvider
{
    private string $base;
    private string $key;
    private string $bucket;

    public function __construct()
    {
        $this->base   = rtrim((string) env('SUPABASE_STORAGE_URL'), '/');
        $this->key    = (string) env('SUPABASE_STORAGE_KEY');
        $this->bucket = (string) env('SUPABASE_STORAGE_BUCKET');
    }

    public function name(): string
    {
        return 'supabase';
    }

    private function http()
    {
        return Http::withToken($this->key)->withHeaders(['apikey' => $this->key]);
    }

    public function put(string $key, string $content, ?string $mimeType = null): void
    {
        $r = $this->http()
            ->withBody($content, $mimeType ?: 'application/octet-stream')
            ->post("{$this->base}/object/{$this->bucket}/{$key}?upsert=true");
        if (!$r->successful()) {
            throw new RuntimeException("Supabase put falhou ({$r->status()}): " . $r->body());
        }
    }

    public function putUploaded(string $key, UploadedFile $file): void
    {
        $this->put($key, (string) file_get_contents($file->getRealPath()), $file->getMimeType());
    }

    public function get(string $key): string
    {
        $r = $this->http()->get("{$this->base}/object/authenticated/{$this->bucket}/{$key}");
        if (!$r->successful()) {
            throw new RuntimeException("Supabase get falhou ({$r->status()}) key={$key}");
        }
        return $r->body();
    }

    public function delete(string $key): void
    {
        $this->http()->delete("{$this->base}/object/{$this->bucket}/{$key}");
    }

    public function exists(string $key): bool
    {
        return $this->http()->head("{$this->base}/object/authenticated/{$this->bucket}/{$key}")->successful();
    }

    public function url(string $key, int $ttlSeconds = 300): string
    {
        // URL assinada nativa do Supabase (privada, TTL).
        $r = $this->http()->post("{$this->base}/object/sign/{$this->bucket}/{$key}", ['expiresIn' => $ttlSeconds]);
        if (!$r->successful()) {
            throw new RuntimeException("Supabase sign falhou ({$r->status()}) key={$key}");
        }
        // signedURL vem relativo ("/object/sign/..."); prefixa o host do storage.
        return $this->base . ($r->json('signedURL') ?? '');
    }

    public function checksum(string $key): string
    {
        return hash('sha256', $this->get($key));
    }

    public function size(string $key): int
    {
        $r = $this->http()->head("{$this->base}/object/authenticated/{$this->bucket}/{$key}");
        return (int) ($r->header('Content-Length') ?: 0);
    }

    public function downloadStream(string $key): StreamedResponse
    {
        $content = $this->get($key);
        return new StreamedResponse(function () use ($content) {
            echo $content;
        }, 200, ['Content-Type' => 'application/octet-stream']);
    }
}
