<?php

namespace App\Console\Commands;

use App\Models\SourceDocVersion;
use App\SourceCode\SourceDocPipeline;
use Illuminate\Console\Command;

/**
 * Fase 3 — reprocessa versões de documentação em partial/failed (ou 'analyzing' pendente de
 * semântica), sem nova GMUD. Re-busca o código no source_commit_sha e refaz a análise até
 * concluir. Versão CONCLUÍDA (completed) é imutável e não é tocada.
 *
 *   php artisan source-doc:reprocess
 *   php artisan source-doc:reprocess --status=partial,failed,analyzing --limit=20
 *   php artisan source-doc:reprocess --id=123
 */
class SourceDocReprocessCommand extends Command
{
    protected $signature = 'source-doc:reprocess {--status=partial,failed,analyzing} {--limit=0} {--id=} {--sleep=1000} {--no-semantic}';
    protected $description = 'Reprocessa a documentação de versões partial/failed/analyzing (sem nova GMUD).';

    public function handle(SourceDocPipeline $pipeline): int
    {
        $runSemantic = !$this->option('no-semantic');

        if ($id = $this->option('id')) {
            $ver = SourceDocVersion::find((int) $id);
            if (!$ver) {
                $this->error("Versão #{$id} não encontrada.");
                return self::FAILURE;
            }
            $targets = collect([$ver]);
        } else {
            $statuses = array_filter(array_map('trim', explode(',', (string) $this->option('status'))));
            $q = SourceDocVersion::whereIn('analysis_status', $statuses)->orderBy('id');
            $limit = (int) $this->option('limit');
            if ($limit > 0) {
                $q->limit($limit);
            }
            $targets = $q->get();
        }

        $this->info("Versões a reprocessar: {$targets->count()}" . ($runSemantic ? '' : ' (sem semântica)'));
        $ok = 0;
        $fail = 0;
        $sleepMs = max(0, (int) $this->option('sleep'));
        foreach ($targets as $ver) {
            if ($ver->analysis_status === 'completed') {
                continue; // imutável
            }
            try {
                $r = $pipeline->reprocessVersion($ver, $runSemantic);
                $this->line("• #{$ver->id} {$r->doc?->path} → {$r->analysis_status}");
                $r->analysis_status === 'completed' || $r->analysis_status === 'partial' ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                $fail++;
                $this->error("✗ #{$ver->id}: " . $e->getMessage());
            }
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        $this->info("Concluído. OK={$ok} Falhas={$fail}");
        return self::SUCCESS;
    }
}
