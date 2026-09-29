<?php

namespace App\Attachments\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FASE 11 — Storage provider S3-compatível (AWS S3 / Cloudflare R2 / Supabase Storage).
 *
 * Usa o disco 's3' do Laravel (config/filesystems.php), configurado por env
 * AWS_* (Supabase: AWS_ENDPOINT=https://{ref}.storage.supabase.co/storage/v1/s3,
 * AWS_USE_PATH_STYLE_ENDPOINT=true). Ativado quando ATTACHMENTS_DRIVER=s3 —
 * ver AttachmentsServiceProvider. Convive com o LocalStorageProvider (legado local).
 *
 * `storage_path` é a KEY no bucket (mesmo esquema "attachments/...").
 */
class S3StorageProvider implements StorageProvider
{
    private const DISK = 's3';

    public function name(): string
    {
        return 's3';
    }

    public function put(string $key, string $content, ?string $mimeType = null): void
    {
        $opts = $mimeType ? ['ContentType' => $mimeType] : [];
        Storage::disk(self::DISK)->put($key, $content, $opts);
    }

    public function putUploaded(string $key, UploadedFile $file): void
    {
        Storage::disk(self::DISK)->putFileAs(
            dirname($key) === '.' ? '' : dirname($key),
            $file,
            basename($key),
        );
    }

    public function get(string $key): string
    {
        $c = Storage::disk(self::DISK)->get($key);
        if ($c === null) {
            throw new RuntimeException("S3StorageProvider::get — arquivo não existe — key={$key}");
        }
        return $c;
    }

    public function delete(string $key): void
    {
        Storage::disk(self::DISK)->delete($key);
    }

    public function exists(string $key): bool
    {
        return Storage::disk(self::DISK)->exists($key);
    }

    public function url(string $key, int $ttlSeconds = 300): string
    {
        // URL assinada nativa do S3/Supabase (TTL). Serve o arquivo direto do bucket.
        return Storage::disk(self::DISK)->temporaryUrl($key, now()->addSeconds($ttlSeconds));
    }

    public function checksum(string $key): string
    {
        // Raramente chamado (o fluxo de upload calcula antes). Baixa e hasheia.
        if (!Storage::disk(self::DISK)->exists($key)) {
            throw new RuntimeException("S3StorageProvider::checksum — arquivo não existe — key={$key}");
        }
        return hash('sha256', (string) Storage::disk(self::DISK)->get($key));
    }

    public function size(string $key): int
    {
        if (!Storage::disk(self::DISK)->exists($key)) {
            throw new RuntimeException("S3StorageProvider::size — arquivo não existe — key={$key}");
        }
        return (int) Storage::disk(self::DISK)->size($key);
    }

    public function downloadStream(string $key): StreamedResponse
    {
        return Storage::disk(self::DISK)->download($key);
    }
}
