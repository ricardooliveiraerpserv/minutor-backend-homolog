<?php

namespace App\Console\Commands;

use App\Models\SourceDocActionLog;
use Illuminate\Console\Command;

/**
 * Central de Fontes — C3 (gate de fila). Recupera execuções de reprocess ÓRFÃS: processos que
 * morreram no meio (deploy/OOM/timeout/conexão perdida) deixam o action_log preso em
 * queued/running → o índice único inflight bloqueia novas tentativas do MESMO fonte (409 eterno).
 *
 * Marca como failed (reason=stale_execution) o que passou do limite. Isso libera a condição
 * inflight (status sai de queued/running) e permite nova tentativa. NÃO toca em versão/documentação
 * e NÃO apaga histórico (a própria linha vira o registro de auditoria da falha).
 */
class SourceDocReapStaleExecutionsCommand extends Command
{
    protected $signature = 'source-doc:reap-stale-executions {--minutes=} {--dry-run}';
    protected $description = 'Marca execuções de reprocess órfãs (queued/running vencidas) como failed/stale_execution.';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('services.source_doc_ai.reap_stale_minutes', 15));
        $cutoff = now()->subMinutes($minutes);

        $q = SourceDocActionLog::query()
            ->where('action', 'reprocess')
            ->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '<', $cutoff);

        $count = (clone $q)->count();
        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$count} execução(ões) órfã(s) (> {$minutes}min) seriam marcadas failed/stale_execution.");
            return self::SUCCESS;
        }

        // updated_at avança → sai do índice inflight (que só considera queued/running).
        $affected = $q->update(['status' => 'failed', 'reason' => 'stale_execution', 'updated_at' => now()]);
        $this->info("Recuperadas {$affected} execução(ões) órfã(s) (> {$minutes}min) → failed/stale_execution.");

        return self::SUCCESS;
    }
}
