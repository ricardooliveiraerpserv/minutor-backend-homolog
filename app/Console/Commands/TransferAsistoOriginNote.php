<?php

namespace App\Console\Commands;

use App\Models\Timesheet;
use App\Services\TimesheetTransferService;
use Illuminate\Console\Command;

/**
 * ASISTO: grava na OBSERVAÇÃO dos apontamentos já migrados para projetos da ASISTO a origem
 * "[Origem] Cliente · Projeto · Ticket" (os novos já nascem assim). Dry-run por padrão.
 */
class TransferAsistoOriginNote extends Command
{
    protected $signature = 'transfer:asisto-origin-note {--execute : grava (sem isto só conta)}';
    protected $description = 'Carimba a origem (cliente/projeto/ticket) na observação dos apontamentos migrados para a ASISTO';

    public function handle(): int
    {
        $customerIds = \App\Models\Customer::withoutGlobalScopes()->where('name', 'ilike', 'ASISTO%')->pluck('id');
        $q = Timesheet::withoutGlobalScopes()->whereNull('deleted_at')
            ->whereIn('customer_id', $customerIds)->whereNotNull('transfer_source_customer_id');
        $total = 0; $changed = 0;
        foreach ($q->cursor() as $ts) {
            $total++;
            $before = $ts->observation;
            TimesheetTransferService::stampOriginNote($ts);
            if ($ts->observation === $before) continue;
            $changed++;
            if ($this->option('execute')) {
                Timesheet::withoutGlobalScopes()->where('id', $ts->id)->update(['observation' => $ts->observation]);
            }
        }
        $this->info(($this->option('execute') ? 'GRAVADO' : 'DRY-RUN') . " — migrados ASISTO: {$total}, observação alterada: {$changed}");
        return self::SUCCESS;
    }
}
