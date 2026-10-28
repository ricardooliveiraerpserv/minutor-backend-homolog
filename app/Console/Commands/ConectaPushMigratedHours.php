<?php

namespace App\Console\Commands;

use App\Jobs\PushMigratedHoursToConectaJob;
use App\Models\Timesheet;
use Illuminate\Console\Command;

/**
 * Backfill/reconciliação: empurra para a Conecta TODOS os apontamentos já migrados (transfer_source
 * preenchido). Útil na largada da integração e para reconciliar. Enfileira em lotes (best-effort).
 */
class ConectaPushMigratedHours extends Command
{
    protected $signature = 'conecta:push-migrated-hours {--sync : Roda o push síncrono em vez de enfileirar}';

    protected $description = 'Empurra para a Conecta todos os apontamentos já migrados (espelho erpserv_migrated_hours).';

    public function handle(): int
    {
        $ids = Timesheet::withoutGlobalScopes()
            ->whereNotNull('transfer_source_customer_id')
            ->pluck('id')->map(fn ($x) => (int) $x)->all();
        // Sincroniza o universo de clientes das regras SEMPRE (p/ listar inclusive sem movimentação).
        app(\App\Services\ConectaMigratedHoursPusher::class)->pushRuleClients();
        $this->info('Clientes das regras sincronizados.');
        if (empty($ids)) {
            $this->warn('Nenhum apontamento migrado para enviar.');
            return self::SUCCESS;
        }
        $this->info(sprintf('%d apontamento(s) migrado(s) → %s', count($ids), $this->option('sync') ? 'push síncrono' : 'fila'));
        foreach (array_chunk($ids, 200) as $batch) {
            if ($this->option('sync')) {
                app(\App\Services\ConectaMigratedHoursPusher::class)->pushIds($batch);
            } else {
                PushMigratedHoursToConectaJob::dispatch($batch);
            }
        }
        $this->info('OK.');
        return self::SUCCESS;
    }
}
