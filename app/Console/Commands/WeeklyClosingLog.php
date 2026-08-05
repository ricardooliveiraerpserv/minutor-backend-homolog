<?php

namespace App\Console\Commands;

use App\Models\ProjectOpenPeriod;
use App\Models\WeekOpenPeriod;
use App\Services\ClosingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Gera o LOG de encerramentos (idempotente) para acompanhamento:
 *  - semanas cujo prazo (2º dia útil da semana seguinte) já venceu (>= ativação);
 *  - reaberturas (semana/mês) cujo auto_close_at (23:59 do dia) já passou.
 * O BLOQUEIO em si é lazy (não depende deste command) — isto só registra os eventos.
 */
class WeeklyClosingLog extends Command
{
    protected $signature = 'closing:log';
    protected $description = 'Registra no closing_logs os encerramentos semanais e auto-fechamentos de reabertura';

    public function handle(ClosingService $svc): int
    {
        $now       = Carbon::now('America/Sao_Paulo');
        $activated = $svc->weeklyActivatedAt();
        $monday    = $svc->weekStart($now->toDateString());

        // 1) Encerramento semanal por prazo (últimas 8 semanas).
        for ($i = 1; $i <= 8; $i++) {
            $ws       = $monday->copy()->subWeeks($i);
            $deadline = $svc->weekDeadline($ws);
            if ($now->gt($deadline) && $deadline->gte($activated)) {
                $svc->logOnce('week_deadline_close', 'week', $deadline->toDateString(), null,
                    "Semana {$ws->toDateString()}–{$ws->copy()->addDays(6)->toDateString()} encerrada (prazo {$deadline->toDateString()} 23:59)");
            }
        }

        // 2) Reabertura SEMANAL expirada (auto_close_at passou).
        WeekOpenPeriod::whereNull('closed_at')->whereNotNull('auto_close_at')
            ->where('auto_close_at', '<', now())->get()
            ->each(function ($p) use ($svc) {
                $ws = Carbon::parse($p->week_start)->toDateString();
                $svc->logOnce('week_reopen_autoclose', 'week', $ws, $p->project_id,
                    ($p->project_id ? "Reabertura da semana {$ws} (projeto {$p->project_id})" : "Reabertura global da semana {$ws}") . ' encerrada às 23:59');
            });

        // 3) Reabertura MENSAL expirada.
        ProjectOpenPeriod::whereNull('closed_at')->whereNotNull('auto_close_at')
            ->where('auto_close_at', '<', now())->get()
            ->each(function ($p) use ($svc) {
                $svc->logOnce('month_reopen_autoclose', 'month', $p->year_month, $p->project_id,
                    "Reabertura da competência {$p->year_month} (projeto {$p->project_id}) encerrada às 23:59");
            });

        $this->info('closing:log ok');
        return self::SUCCESS;
    }
}
