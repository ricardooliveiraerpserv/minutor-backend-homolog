<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula o flag sla_ever_paused de TODOS os chamados a partir da fonte de verdade (status atual
 * + eventos status_changed que entraram num status pausante). Idempotente. Use se suspeitar de desvio.
 */
class HelpDeskRecomputeSlaEverPaused extends Command
{
    protected $signature = 'help-desk:recompute-sla-ever-paused';
    protected $description = 'Recalcula sla_ever_paused (denormalização de performance da listagem)';

    public function handle(): int
    {
        $pausingKeys = DB::table('helpdesk_statuses')->where('sla_paused', true)->pluck('key')->all();
        $pausingIds  = DB::table('helpdesk_statuses')->where('sla_paused', true)->pluck('id')->all();

        // zera e reconstrói (fonte de verdade = status atual OU histórico de eventos)
        DB::table('helpdesk_tickets')->update(['sla_ever_paused' => false]);

        if (empty($pausingKeys)) { $this->info('Nenhum status pausante — todos false.'); return self::SUCCESS; }

        $a = !empty($pausingIds)
            ? DB::table('helpdesk_tickets')->whereIn('status_id', $pausingIds)->update(['sla_ever_paused' => true])
            : 0;

        $b = DB::table('helpdesk_tickets')
            ->where('sla_ever_paused', false)
            ->whereIn('id', function ($q) use ($pausingKeys) {
                $q->select('ticket_id')->from('helpdesk_ticket_events')
                  ->where('event_type', 'status_changed')->whereIn('to_value', $pausingKeys);
            })
            ->update(['sla_ever_paused' => true]);

        $total = DB::table('helpdesk_tickets')->where('sla_ever_paused', true)->count();
        $this->info("sla_ever_paused=true: {$total} chamados (por status atual: {$a}, por histórico: {$b}).");
        return self::SUCCESS;
    }
}
