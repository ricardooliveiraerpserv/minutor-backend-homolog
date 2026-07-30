<?php

namespace App\Console\Commands;

use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTrigger;
use App\Services\HelpDeskTriggerEngine;
use Illuminate\Console\Command;

/**
 * Dispara os gatilhos do tipo "parado num status por X tempo" (idle_in_status).
 * Varre os chamados não-encerrados parados além do menor limiar e deixa o motor avaliar
 * as condições (status + idle_hours) com dedup. Agendado de hora em hora.
 */
class HelpDeskRunIdleTriggers extends Command
{
    protected $signature = 'help-desk:run-idle-triggers';
    protected $description = 'Dispara gatilhos por tempo parado (auto-fechar/auto-tag de chamados ociosos)';

    public function handle(): int
    {
        $triggers = HelpDeskTrigger::where('enabled', true)->where('event', 'idle_in_status')->get();
        if ($triggers->isEmpty()) {
            $this->info('Nenhum gatilho idle habilitado.');
            return self::SUCCESS;
        }

        // Menor limiar (em horas) entre os gatilhos → limita a busca de candidatos.
        $minHours = $triggers->flatMap(fn ($t) => collect($t->conditions ?? [])
            ->where('field', 'idle_hours')->pluck('value'))->map(fn ($v) => (int) $v)->filter()->min() ?? 24;

        $candidates = HelpDeskTicket::query()
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<=', now()->subHours($minHours))
            ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
            ->limit(500)->get();

        $before = $candidates->count();
        foreach ($candidates as $ticket) {
            HelpDeskTriggerEngine::queue('idle_in_status', $ticket);
        }

        $this->info("Idle: {$triggers->count()} gatilho(s), {$before} chamado(s) parado(s) avaliado(s) (limiar mín. {$minHours}h).");
        return self::SUCCESS;
    }
}
