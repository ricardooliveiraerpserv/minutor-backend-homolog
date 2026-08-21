<?php

namespace App\Jobs;

use App\Attachments\Storage\StorageProvider;
use App\Models\GmudPackage;
use App\Models\GmudPackageFile;
use App\Models\HelpDeskTicketEvent;
use App\SourceCode\Gmud\GmudExtractionException;
use App\SourceCode\Gmud\GmudMatchingService;
use App\SourceCode\Gmud\GmudZipExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GMUD G1/G2 — extrai o ZIP com segurança e casa os fontes com o Git, de forma ASSÍNCRONA e
 * ISOLADA (conexão database + fila source-doc; supervisor sourcedoc-worker). NUNCA commita, NUNCA
 * publica: só produz a evidência (gmud_package_files) e o resultado do matching. É a fronteira que
 * garante "upload não gera commit".
 */
class GmudExtractAnalyzeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $packageId)
    {
        // Fila dedicada: nunca roda inline (mesmo com QUEUE_CONNECTION=sync global) e fica isolada.
        $this->onConnection('database')->onQueue('source-doc');
    }

    public function handle(GmudZipExtractor $extractor, GmudMatchingService $matcher, StorageProvider $storage): void
    {
        $package = GmudPackage::with('attachment')->find($this->packageId);
        if (! $package) {
            return;
        }
        if (! $package->attachment) {
            $this->fail($package, 'attachment_missing', 'Pacote sem ZIP anexado.');
            return;
        }

        // Idempotência de re-execução: limpa a evidência anterior antes de reconstruir.
        $package->files()->delete();
        $package->update(['status' => GmudPackage::STATUS_EXTRACTING, 'error' => null]);

        try {
            $bytes = $storage->get($package->attachment->storage_path);
        } catch (\Throwable $e) {
            $this->fail($package, 'zip_read_failed', 'Falha ao ler o ZIP armazenado.');
            return;
        }

        try {
            $result = $extractor->extract($bytes);
        } catch (GmudExtractionException $e) {
            $this->fail($package, $e->errorCode, $e->getMessage());
            return;
        } catch (\Throwable $e) {
            Log::warning('gmud_analyze.extract_error', ['package' => $package->id, 'error' => $e->getMessage()]);
            $this->fail($package, 'extract_error', 'Falha inesperada na extração.');
            return;
        }

        foreach ($result['files'] as $f) {
            GmudPackageFile::create(array_merge(['gmud_package_id' => $package->id], $f));
        }

        $package->update(['status' => GmudPackage::STATUS_ANALYZING]);

        try {
            $matcher->matchPackage($package);
        } catch (\Throwable $e) {
            // Matching é best-effort sobre a evidência já gravada; falha não apaga o pacote.
            Log::warning('gmud_analyze.match_error', ['package' => $package->id, 'error' => $e->getMessage()]);
        }

        $package->update(['status' => GmudPackage::STATUS_ANALYZED]);

        $this->auditAnalyzed($package, $result);
    }

    private function auditAnalyzed(GmudPackage $package, array $result): void
    {
        $counts = $package->files()
            ->selectRaw('match_status, count(*) as c')
            ->groupBy('match_status')->pluck('c', 'match_status')->toArray();

        try {
            HelpDeskTicketEvent::log($package->ticket_id, 'gmud_package_analyzed', [
                'meta' => [
                    'package_id'   => $package->id,
                    'files'        => count($result['files']),
                    'skipped'      => count($result['skipped']),
                    'match_counts' => $counts,
                    // Evidência explícita da garantia central desta entrega.
                    'committed'    => false,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('gmud_analyze.event_failed', ['package' => $package->id, 'error' => $e->getMessage()]);
        }
    }

    private function fail(GmudPackage $package, string $code, string $message): void
    {
        $package->update(['status' => GmudPackage::STATUS_FAILED, 'error' => mb_substr($code . ': ' . $message, 0, 300)]);
        try {
            HelpDeskTicketEvent::log($package->ticket_id, 'gmud_package_failed', [
                'meta' => ['package_id' => $package->id, 'error_code' => $code, 'committed' => false],
            ]);
        } catch (\Throwable $e) {
            // silencioso — já logado no fluxo principal
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($package = GmudPackage::find($this->packageId)) {
            $package->update(['status' => GmudPackage::STATUS_FAILED, 'error' => 'job falhou']);
        }
    }
}
