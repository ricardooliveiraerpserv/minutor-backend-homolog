<?php

namespace App\Services;

use App\Attachments\AttachmentService;
use App\Attachments\Storage\StorageProvider;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * ANEXOS Minutor ⇄ Movidesk (ida e volta).
 *  - IDA: anexos de uma entidade do Minutor (chamado ou interação) sobem para a AÇÃO correspondente no
 *    Movidesk. Marca `metadata.movidesk_sent` para não reenviar.
 *  - VOLTA: anexos de uma ação do Movidesk são baixados e gravados como anexo da interação/chamado.
 * Limite do Movidesk: 10 MB por arquivo (maiores são pulados e logados).
 */
class MovideskAttachmentSync
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const CATEGORIES = ['attachment', 'image'];

    public function __construct(private MovideskService $movidesk) {}

    /** IDA: envia os anexos ainda não enviados de $entityType/$entityId para a ação $actionId do chamado $mdTicket. */
    public function push(string $entityType, int $entityId, int $mdTicket, int $actionId): int
    {
        $atts = Attachment::query()->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->whereNull('deleted_at')->whereIn('category', self::CATEGORIES)->orderBy('id')->get();
        if ($atts->isEmpty()) return 0;
        $storage = app(StorageProvider::class);
        $n = 0;
        foreach ($atts as $a) {
            $meta = (array) ($a->metadata ?? []);
            if (!empty($meta['movidesk_sent']) || !empty($meta['movidesk_path'])) continue; // já foi / veio de lá
            if ((int) $a->size_bytes > self::MAX_BYTES) {
                Log::warning('📤 [MOVIDESK ANEXO] maior que 10 MB — não enviado', ['attachment_id' => $a->id, 'size' => $a->size_bytes]);
                $meta['movidesk_sent'] = 'skip:size'; $a->metadata = $meta; $a->saveQuietly();
                continue;
            }
            try { $bytes = $storage->get($a->storage_path); } catch (\Throwable $e) { Log::warning('📤 [MOVIDESK ANEXO] arquivo ilegível', ['attachment_id' => $a->id, 'e' => $e->getMessage()]); continue; }
            $ok = $this->movidesk->uploadActionFiles($mdTicket, $actionId, [[
                'name' => $a->original_name ?: $a->file_name, 'content' => $bytes, 'mime' => $a->mime_type,
            ]]);
            if (!$ok) break; // tenta de novo na próxima passada
            $meta['movidesk_sent'] = "{$mdTicket}:{$actionId}";
            $a->metadata = $meta; $a->saveQuietly();
            $n++;
        }
        return $n;
    }

    /**
     * VOLTA: baixa os anexos de uma ação do Movidesk e grava na entidade do Minutor.
     * @param array $mdAttachments [['fileName'=>..., 'path'=>...], ...]
     */
    public function pull(string $entityType, int $entityId, array $mdAttachments, string $visibility, ?int $actorId = null): int
    {
        if (!$mdAttachments) return 0;
        $svc = app(AttachmentService::class);
        $actor = ($actorId ? User::find($actorId) : null) ?? User::query()->orderBy('id')->first();
        $n = 0;
        foreach ($mdAttachments as $md) {
            $path = (string) ($md['path'] ?? '');
            if ($path === '') continue;
            $dup = Attachment::query()->where('entity_type', $entityType)->where('entity_id', $entityId)
                ->whereNull('deleted_at')->where('metadata->movidesk_path', $path)->exists();
            if ($dup) continue;
            $file = $this->movidesk->downloadFile($path);
            if (!$file || strlen($file['content']) === 0) { Log::warning('📥 [MOVIDESK ANEXO] download falhou', ['path' => $path]); continue; }
            $name = (string) ($md['fileName'] ?? ('anexo-' . substr($path, 0, 8)));
            $tmp = tempnam(sys_get_temp_dir(), 'mvatt');
            file_put_contents($tmp, $file['content']);
            try {
                $uf = new UploadedFile($tmp, $name, $file['mime'], null, true);
                $att = $svc->store($actor, [
                    'entity_type' => $entityType, 'entity_id' => $entityId,
                    'category' => str_starts_with(strtolower($file['mime']), 'image/') ? 'image' : 'attachment',
                    'visibility' => $visibility === 'customer' ? 'customer' : 'internal',
                    'file' => $uf,
                ]);
                $meta = (array) ($att->metadata ?? []);
                $meta['movidesk_path'] = $path; // anti-eco: não volta pro Movidesk e não baixa de novo
                $att->metadata = $meta; $att->saveQuietly();
                $n++;
            } catch (\Throwable $e) {
                Log::warning('📥 [MOVIDESK ANEXO] falha ao gravar', ['path' => $path, 'e' => $e->getMessage()]);
            } finally {
                @unlink($tmp);
            }
        }
        return $n;
    }
}
