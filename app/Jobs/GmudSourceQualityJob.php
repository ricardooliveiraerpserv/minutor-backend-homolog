<?php

namespace App\Jobs;

use App\Models\GmudPackage;
use App\Models\HelpDeskTicketComment;
use App\SourceCode\Gmud\GmudZipExtractor;
use App\Services\SourceDocQualityService;
use App\Attachments\Storage\StorageProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GMUD com código-fonte: roda o CodeAnalysis em CADA arquivo do zip (a pasta inteira) e posta o
 * resultado (nota A-F + possíveis correções) em uma INTERAÇÃO INTERNA POR ARQUIVO no chamado.
 * Best-effort: se o serviço estiver off/erro, registra a nota de falha e segue — nunca quebra a GMUD.
 * Legenda de cor: A/B verde · C amarelo · demais vermelho.
 */
class GmudSourceQualityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    private const MAX_FILES = 40;
    private const POLL_TRIES = 20;   // ~60s por arquivo
    private const POLL_SLEEP = 3;

    public function __construct(public int $packageId) {}

    public function handle(GmudZipExtractor $extractor, StorageProvider $storage, SourceDocQualityService $quality): void
    {
        if (! $quality->enabled()) {
            Log::info('gmud_quality.skip_disabled', ['package' => $this->packageId]);
            return;
        }
        $package = GmudPackage::with(['attachment', 'ticket', 'files'])->find($this->packageId);
        if (! $package || ! $package->attachment || ! $package->ticket) {
            return;
        }

        try {
            $bytes = $storage->get($package->attachment->storage_path);
        } catch (\Throwable $e) {
            Log::warning('gmud_quality.zip_read_failed', ['package' => $package->id, 'error' => $e->getMessage()]);
            return;
        }

        $files = $package->files->take(self::MAX_FILES);
        $paths = $files->pluck('path_in_zip')->filter()->all();
        $contents = $extractor->contentsFor($bytes, $paths); // path_in_zip => conteúdo

        foreach ($files as $file) {
            $content = $contents[$file->path_in_zip] ?? null;
            if ($content === null || $content === '') {
                continue;
            }
            $this->analyzeFile($package, $file, (string) $content, $quality);
        }
    }

    /** Analisa 1 fonte e GRAVA o resultado NO PRÓPRIO fonte (painel GMUD). Falha vira comentário. */
    private function analyzeFile(GmudPackage $package, \App\Models\GmudPackageFile $file, string $content, SourceDocQualityService $quality): void
    {
        $filename = (string) ($file->filename ?: basename((string) $file->path_in_zip));
        try {
            $sub = $quality->analyze($filename, $content, ['source' => 'gmud', 'ticket_id' => $package->ticket_id], true); // force: sempre reanalisa com o ruleset atual (sem reuse)
            $jobId = (string) ($sub['job_id'] ?? '');
            $result = $this->poll($quality, $jobId, $sub);
            if (! $result || ($result['status'] ?? null) !== 'completed') {
                $motivo = ! $result ? 'A análise não concluiu no tempo esperado.' : (string) ($result['error'] ?? 'Análise não concluída.');
                $this->postComment($package, $this->failBody($filename, $motivo));
                return;
            }
            $findings = is_array($result['findings'] ?? null) ? $result['findings'] : [];
            $file->update([
                'quality_grade'       => (string) ($result['grade'] ?? '') ?: null,
                'quality_score'       => is_numeric($result['score'] ?? null) ? (int) $result['score'] : null,
                'quality_findings'    => $this->normFindings($findings),
                'quality_analyzed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('gmud_quality.analyze_error', ['package' => $package->id, 'file' => $filename, 'error' => $e->getMessage()]);
            $this->postComment($package, $this->failBody($filename, 'CodeAnalysis indisponível no momento.'));
        }
    }

    /** Normaliza os achados p/ o shape dos cards (mesmo do painel/FE). */
    private function normFindings(array $findings): array
    {
        $items = [];
        foreach ($findings as $f) {
            $line = $f['line'] ?? $f['start_line'] ?? null;
            $items[] = [
                'severity'       => strtoupper((string) ($f['severity'] ?? $f['analyzer_severity'] ?? 'INFO')),
                'category'       => (string) ($f['category'] ?? $f['group'] ?? ''),
                'rule'           => (string) ($f['rule'] ?? ''),
                'title'          => (string) ($f['title'] ?? ''),
                'description'    => (string) ($f['description'] ?? $f['desc'] ?? ''),
                'line'           => is_numeric($line) ? (int) $line : null,
                'snippet'        => (string) ($f['snippet'] ?? $f['code'] ?? ''),
                'count'          => (int) ($f['count'] ?? 1),
                'recommendation' => (string) ($f['recommendation'] ?? $f['fix'] ?? ''),
            ];
        }
        return $items;
    }

    /** Faz polling do job até completar/falhar (ou timeout). Retorna o corpo final ou null. */
    private function poll(SourceDocQualityService $quality, string $jobId, array $initial): ?array
    {
        // O POST pode já vir com status terminal.
        if (in_array($initial['status'] ?? null, ['completed', 'failed'], true)) {
            return $initial;
        }
        if ($jobId === '') {
            return null;
        }
        for ($i = 0; $i < self::POLL_TRIES; $i++) {
            sleep(self::POLL_SLEEP);
            $job = $quality->getJob($jobId);
            if (! $job) {
                continue;
            }
            if (in_array($job['status'] ?? null, ['completed', 'failed'], true)) {
                return $job;
            }
        }
        return null;
    }

    private function failBody(string $filename, string $motivo): string
    {
        return '<div><p>📊 <b>CodeAnalysis — ' . e($filename) . '</b></p>'
            . '<p style="color:#dc2626">Não foi possível pontuar este fonte: ' . e($motivo) . '</p>'
            . $this->legend() . '</div>';
    }

    private function legend(): string
    {
        return '<p style="font-size:11px;color:#6b7280;margin-top:8px">Legenda: '
            . '<span style="color:#059669;font-weight:bold">A/B</span> verde · '
            . '<span style="color:#d97706;font-weight:bold">C</span> amarelo · '
            . '<span style="color:#dc2626;font-weight:bold">D/E/F</span> vermelho</p>';
    }

    private function postComment(GmudPackage $package, string $body): void
    {
        HelpDeskTicketComment::create([
            'ticket_id'      => $package->ticket_id,
            'author_user_id' => $package->uploaded_by,
            'body'           => $body,
            'visibility'     => 'internal',
            'is_system'      => false,
            'channel'        => 'interno',
        ]);
    }
}
