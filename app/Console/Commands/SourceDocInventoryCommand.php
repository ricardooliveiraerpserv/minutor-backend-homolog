<?php

namespace App\Console\Commands;

use App\Jobs\InventorySourceRepoJob;
use App\Models\ClientSourceRepo;
use App\SourceCode\SourceDocInventory;
use Illuminate\Console\Command;

/**
 * Central de Fontes — C3.5. Inventário do acervo (determinístico, IA ZERO). Idempotente/retomável
 * (diff por blob + cursor). Motor congelado; GitHub read-only.
 *
 *   php artisan source-doc:inventory                 # todos os repos ativos, lote da config
 *   php artisan source-doc:inventory --incremental   # scheduler: só novos/alterados (diff por blob)
 *   php artisan source-doc:inventory --customer=3
 *   php artisan source-doc:inventory --repo=5 --batch=200
 *   php artisan source-doc:inventory --dispatch      # enfileira 1 job por repo (worker source-doc)
 */
class SourceDocInventoryCommand extends Command
{
    protected $signature = 'source-doc:inventory
        {--customer= : Só os repos deste cliente}
        {--repo= : Só este client_source_repo}
        {--batch= : Máx. de NOVOS documentados por repo nesta execução (0=config)}
        {--incremental : Modo scheduler (diff por blob; nada novo → no-op)}
        {--dispatch : Enfileira 1 job por repo na fila source-doc em vez de rodar inline}';

    protected $description = 'Inventário/cobertura do acervo GitHub: cataloga + documenta determinístico (sem IA).';

    public function handle(SourceDocInventory $inv): int
    {
        $batch = (int) ($this->option('batch') ?: 0);
        $repos = ClientSourceRepo::query()->where('active', true)
            ->when($this->option('customer'), fn ($q) => $q->where('customer_id', (int) $this->option('customer')))
            ->when($this->option('repo'), fn ($q) => $q->whereKey((int) $this->option('repo')))
            ->get();

        if ($repos->isEmpty()) {
            $this->warn('Nenhum repositório ativo encontrado.');
            return self::SUCCESS;
        }

        $totNew = $totChg = $totCov = 0;
        foreach ($repos as $repo) {
            if ($this->option('dispatch')) {
                InventorySourceRepoJob::dispatch($repo->id, $batch);
                $this->line("  enfileirado: {$repo->owner}/{$repo->repository}@{$repo->branch}");
                continue;
            }
            $cov = $inv->scanRepo($repo, $batch);
            $totNew += $cov->new_files; $totChg += $cov->changed_files; $totCov += $cov->cataloged;
            $this->line(sprintf(
                '  %s/%s@%s [%s]: git=%d eleg=%d novos=%d cobertos=%d desatualizados=%d ignorados=%d · catalogados=%d det=%d sem=%d idx=%d',
                $repo->owner, $repo->repository, $repo->branch, $cov->scan_status,
                $cov->github_files, $cov->eligible_source_files, $cov->new_files, $cov->unchanged_files,
                $cov->changed_files, $cov->ignored_files, $cov->cataloged, $cov->deterministic, $cov->semantic, $cov->indexed
            ));
            if ($cov->scan_status === 'rate_limited') {
                $this->warn("    rate-limit atingido — retoma no próximo ciclo (cursor salvo).");
            }
        }
        if (! $this->option('dispatch')) {
            $this->info("Inventário: novos={$totNew} desatualizados={$totChg} catalogados(total)={$totCov}.");
        }

        return self::SUCCESS;
    }
}
