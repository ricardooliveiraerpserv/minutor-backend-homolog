<?php

namespace App\Documents;

use App\Attachments\AttachmentService;
use App\Jobs\RenderDocumentJob;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Orquestra a geração de documentos: cache por (tipo+entidade+versão+hash) →
 * render (DocumentRenderer) → armazenamento (Attachment FASE 11) → Document → auditoria.
 * Genérico: serve qualquer document_type. NÃO conhece Proposta/Contrato.
 * Suporta geração SÍNCRONA (generate) e ASSÍNCRONA por fila (generateAsync + fulfill).
 */
class DocumentService
{
    public function __construct(
        private DocumentRenderer $renderer,
        private AttachmentService $attachments,
    ) {}

    /** SÍNCRONO: renderiza na hora. */
    public function generate(array $spec, array $data, User $actor): Document
    {
        $r = $this->prepare($spec, $data, $actor);
        if ($r['cache_hit']) {
            return $r['document'];
        }
        return $this->materialize($r['document'], $spec, $data, $actor, $r['regen']);
    }

    /** ASSÍNCRONO: cria o Document em 'fila', despacha o job e retorna imediatamente. */
    public function generateAsync(array $spec, array $data, User $actor): Document
    {
        $r = $this->prepare($spec, $data, $actor);
        if ($r['cache_hit']) {
            return $r['document']; // já existe PDF — nada a enfileirar
        }
        $doc = $r['document'];
        $doc->update(['status' => 'fila']);
        // Fila DEDICADA (contexto oficial item 4): documentos isolados de CRM/scheduler/integrações.
        RenderDocumentJob::dispatch($doc->id, $spec, $data, $actor->id)
            ->onQueue(config('documents.queue', 'documents'));
        return $doc;
    }

    /** Chamado pelo RenderDocumentJob — materializa o documento pendente. */
    public function fulfill(int $documentId, array $spec, array $data, int $actorId): Document
    {
        $doc   = Document::findOrFail($documentId);
        $actor = User::findOrFail($actorId);
        $regen = (bool) $doc->attachment_id;
        return $this->materialize($doc, $spec, $data, $actor, $regen);
    }

    /** Nova versão (mantém codigo; versao = max+1). */
    public function newVersion(array $spec, array $data, User $actor, bool $async = false): Document
    {
        $next = (int) Document::query()
            ->where('document_type', $spec['document_type'])
            ->where('entity_type', $spec['entity_type'] ?? null)
            ->where('entity_id', $spec['entity_id'] ?? null)
            ->max('versao') + 1;
        $spec['versao'] = $next;
        return $async ? $this->generateAsync($spec, $data, $actor) : $this->generate($spec, $data, $actor);
    }

    // ── internos ────────────────────────────────────────────────────────────

    /** Resolve cache + cria/preenche o Document (sem render). */
    private function prepare(array $spec, array $data, User $actor): array
    {
        $type       = $spec['document_type'];
        $template   = $spec['template'];
        $renderer   = $spec['renderer'] ?? config('documents.default_renderer', 'chromium');
        $entityType = $spec['entity_type'] ?? null;
        $entityId   = $spec['entity_id'] ?? null;
        $versao     = (int) ($spec['versao'] ?? 1);

        $hash = $this->computeHash($type, $entityType, $entityId, $versao, $template, $renderer, $data);

        $existing = Document::query()
            ->where('document_type', $type)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('versao', $versao)
            ->orderByDesc('id')
            ->first();

        if ($existing && $existing->hash === $hash && $existing->attachment_id) {
            return ['document' => $existing, 'cache_hit' => true, 'regen' => false];
        }

        $doc = $existing ?: new Document();
        $doc->fill([
            'document_type'   => $type,
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'codigo'          => $spec['codigo'] ?? null,
            'versao'          => $versao,
            'status'          => $spec['status'] ?? 'em_elaboracao',
            'template'        => $template,
            'renderer'        => $renderer,
            'hash'            => $hash,
            'params_snapshot' => $data,
        ]);
        $doc->save();

        return ['document' => $doc, 'cache_hit' => false, 'regen' => (bool) ($existing && $existing->attachment_id)];
    }

    /** Render + Attachment + status + auditoria + observabilidade + limites (item 4). */
    private function materialize(Document $doc, array $spec, array $data, User $actor, bool $regen): Document
    {
        $renderer = $spec['renderer'] ?? config('documents.default_renderer', 'chromium');
        $opts     = $spec['opts'] ?? [];

        // Observabilidade: tempo de render.
        $t0  = microtime(true);
        $pdf = $this->renderer->render($spec['template'], $data, $renderer, $opts);
        $renderMs = (int) round((microtime(true) - $t0) * 1000);

        // Limites de tamanho (item 4): >30MB bloqueia (salvo allow_large); >20MB alerta.
        $bytes  = strlen($pdf);
        $mb     = $bytes / 1048576;
        $alert  = (int) config('documents.limits.alert_mb', 20);
        $block  = (int) config('documents.limits.block_mb', 30);
        if ($mb > $block && empty($spec['allow_large'])) {
            \Log::warning('[documents] PDF bloqueado por tamanho', ['type' => $doc->document_type, 'codigo' => $doc->codigo, 'mb' => round($mb, 1), 'limite' => $block]);
            throw new \RuntimeException(sprintf('PDF de %.1f MB excede o limite de %d MB. Reduza imagens/páginas ou confirme allow_large.', $mb, $block));
        }
        if ($mb > $alert) {
            \Log::warning('[documents] PDF acima do alerta', ['type' => $doc->document_type, 'codigo' => $doc->codigo, 'mb' => round($mb, 1), 'alerta' => $alert]);
        }

        $att = $this->storePdf($pdf, $doc, $actor);

        $doc->update([
            'attachment_id' => $att->id,
            'render_ms'     => $renderMs,
            'status'        => $spec['status'] ?? 'gerado',
            'generated_at'  => now(),
            'generated_by'  => $actor->id,
        ]);

        $doc->logEvent($regen ? DocumentEvent::TYPE_REGENERADO : DocumentEvent::TYPE_CRIADO, [
            'versao' => $doc->versao, 'renderer' => $renderer, 'hash' => $doc->hash, 'attachment_id' => $att->id,
            'render_ms' => $renderMs, 'size_bytes' => $bytes,
        ], $actor->id);

        // Observabilidade (item 4): render_ms, tamanho, motor, memória do processo PHP.
        \Log::info('[documents] render', [
            'document_id' => $doc->id, 'type' => $doc->document_type, 'codigo' => $doc->codigo, 'versao' => $doc->versao,
            'renderer' => $renderer, 'render_ms' => $renderMs, 'size_kb' => (int) round($bytes / 1024),
            'php_peak_mb' => (int) round(memory_get_peak_usage(true) / 1048576), 'regen' => $regen,
        ]);

        return $doc->fresh('attachment');
    }

    private function storePdf(string $bytes, Document $doc, User $actor)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'doc_') . '.pdf';
        file_put_contents($tmp, $bytes);
        $name = ($doc->codigo ? str_replace(['/', ' '], '-', $doc->codigo) : ('documento-' . $doc->id)) . "-v{$doc->versao}.pdf";
        $uploaded = new UploadedFile($tmp, $name, 'application/pdf', null, true);

        try {
            return $this->attachments->store($actor, [
                'file'        => $uploaded,
                'entity_type' => 'DOCUMENT',
                'entity_id'   => $doc->id,
                'category'    => 'generated_pdf',
                'visibility'  => 'internal',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function computeHash(string $type, ?string $et, $eid, int $versao, string $template, string $renderer, array $data): string
    {
        return hash('sha256', json_encode([
            'type' => $type, 'entity_type' => $et, 'entity_id' => $eid,
            'versao' => $versao, 'template' => $template, 'renderer' => $renderer, 'data' => $data,
        ]));
    }
}
