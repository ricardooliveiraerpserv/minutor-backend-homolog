<?php

namespace App\Console\Commands;

use App\Models\SourceDoc;
use App\Models\SourceDocEntity;
use App\Models\SourceDocIndex;
use App\SourceCode\SourceDocIndexer;
use Illuminate\Console\Command;

/**
 * Central de Fontes — C2. (Re)constrói o read-model de busca a partir do deterministic_json.
 * Desacoplado do motor (congelado): atualização por RECONCILIAÇÃO de staleness.
 *
 *   php artisan source-doc:index            # reindexa só os STALE (default)
 *   php artisan source-doc:index --rebuild  # trunca tudo e reconstrói (descartável)
 *   php artisan source-doc:index --doc=9
 *   php artisan source-doc:index --customer=3
 */
class SourceDocIndexCommand extends Command
{
    protected $signature = 'source-doc:index
        {--rebuild : Trunca o índice e reconstrói tudo}
        {--doc= : Reindexa apenas o source_doc informado}
        {--customer= : Reindexa apenas os fontes do cliente}
        {--all : Reindexa todos (não só os stale)}';

    protected $description = 'Constrói/atualiza o índice de busca técnica (C2) — read-model derivado, descartável.';

    public function handle(SourceDocIndexer $indexer): int
    {
        if ($this->option('rebuild')) {
            $this->warn('Rebuild: truncando o read-model (é descartável)…');
            SourceDocEntity::query()->delete();
            SourceDocIndex::query()->delete();
        }

        $q = SourceDoc::query()->whereNotNull('current_version_id');
        if ($doc = $this->option('doc')) {
            $q->whereKey((int) $doc);
        }
        if ($cust = $this->option('customer')) {
            $q->where('customer_id', (int) $cust);
        }

        $reindexAll = $this->option('rebuild') || $this->option('all') || $this->option('doc');

        $indexed = 0; $skipped = 0; $failed = 0;
        $q->with('currentVersion:id,source_doc_id,source_blob_sha')->chunkById(200, function ($docs) use ($indexer, $reindexAll, &$indexed, &$skipped, &$failed) {
            foreach ($docs as $doc) {
                if (! $reindexAll && ! $indexer->isStale($doc)) {
                    $skipped++;
                    continue;
                }
                try {
                    $indexer->index($doc) ? $indexed++ : $failed++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  #{$doc->id} {$doc->filename}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Indexados: {$indexed} · pulados (já válidos): {$skipped} · falhas: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
