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
            $this->analyzeAndComment($package, (string) ($file->filename ?: basename((string) $file->path_in_zip)), (string) $content, $quality);
        }
    }

    private function analyzeAndComment(GmudPackage $package, string $filename, string $content, SourceDocQualityService $quality): void
    {
        try {
            $sub = $quality->analyze($filename, $content, ['source' => 'gmud', 'ticket_id' => $package->ticket_id]);
            $jobId = (string) ($sub['job_id'] ?? '');
            $result = $this->poll($quality, $jobId, $sub);
            if (! $result) {
                $this->postComment($package, $this->failBody($filename, 'A análise não concluiu no tempo esperado.'));
                return;
            }
            $status = $result['status'] ?? null;
            if ($status !== 'completed') {
                $this->postComment($package, $this->failBody($filename, (string) ($result['error'] ?? 'Análise não concluída.')));
                return;
            }
            $this->postComment($package, $this->resultBody($filename, $result));
        } catch (\Throwable $e) {
            Log::warning('gmud_quality.analyze_error', ['package' => $package->id, 'file' => $filename, 'error' => $e->getMessage()]);
            $this->postComment($package, $this->failBody($filename, 'CodeAnalysis indisponível no momento.'));
        }
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

    private function gradeColor(?string $grade): string
    {
        $g = strtoupper((string) $grade);
        if (in_array($g, ['A', 'B'], true)) return '#059669'; // verde
        if ($g === 'C') return '#d97706';                     // amarelo
        return '#dc2626';                                     // vermelho
    }

    private function resultBody(string $filename, array $r): string
    {
        $grade = (string) ($r['grade'] ?? '—');
        $score = $r['score'] ?? null;
        $color = $this->gradeColor($grade);
        $findings = is_array($r['findings'] ?? null) ? $r['findings'] : [];

        $html = '<div>';
        $html .= '<p>📊 <b>CodeAnalysis — ' . e($filename) . '</b></p>';
        $html .= '<p>Nota: <span style="color:' . $color . ';font-weight:bold;font-size:17px">' . e($grade) . '</span>'
            . ($score !== null ? ' · <b>' . (int) $score . '</b>/100' : '')
            . ' · ' . count($findings) . ' achado(s)</p>';

        if ($findings) {
            $html .= '<p><b>Possíveis correções:</b></p><ul style="margin:4px 0 0;padding-left:18px">';
            foreach ($findings as $f) {
                $sev = strtoupper((string) ($f['severity'] ?? $f['analyzer_severity'] ?? ''));
                $title = (string) ($f['title'] ?? $f['rule'] ?? 'Achado');
                $rec = trim((string) ($f['recommendation'] ?? ''));
                $line = isset($f['line']) && $f['line'] ? ' (linha ' . (int) $f['line'] . ')' : '';
                $html .= '<li>' . ($sev ? '<b>[' . e($sev) . ']</b> ' : '') . e($title) . e($line)
                    . ($rec !== '' ? ' — ' . e($rec) : '') . '</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<p style="color:#059669">Nenhuma correção sugerida. ✅</p>';
        }

        $html .= $this->legend();
        $html .= '</div>';
        return $html;
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
