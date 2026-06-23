<?php

namespace App\Jobs;

use App\Documents\DocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Geração assíncrona de documento (Fase 0.8). Renderiza fora do request → não bloqueia
 * a aplicação nem expõe o pico de memória do Chromium ao web server.
 */
class RenderDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(
        public int $documentId,
        public array $spec,
        public array $data,
        public int $actorId,
    ) {}

    public function handle(DocumentService $svc): void
    {
        $svc->fulfill($this->documentId, $this->spec, $this->data, $this->actorId);
    }
}
