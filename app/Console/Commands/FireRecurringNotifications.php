<?php

namespace App\Console\Commands;

use App\Http\Controllers\NotificationController;
use App\Models\AppNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Dispara avisos RECORRENTES que vencem hoje (a cada X dias / dia X do mês / Xº dia útil).
 * "Disparar" = reabrir p/ todos + reenviar e-mail (NotificationController::fire). Roda 1x/dia.
 */
class FireRecurringNotifications extends Command
{
    protected $signature = 'notifications:fire-recurring {--date= : Data de referência p/ teste} {--scope=daily : daily|hours}';
    protected $description = 'Dispara avisos recorrentes da Central que vencem agora';

    public function handle(NotificationController $ctrl): int
    {
        $today = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $scope = $this->option('scope') === 'hours' ? 'hours' : 'daily';

        $candidates = AppNotification::where('is_template', false)
            ->where('recurrence', '!=', 'none')
            ->whereNotNull('recurrence_value')
            // scope=hours roda de hora em hora só p/ every_hours; daily (1x/dia) p/ o resto.
            ->when($scope === 'hours', fn ($q) => $q->where('recurrence', 'every_hours'))
            ->when($scope === 'daily', fn ($q) => $q->where('recurrence', '!=', 'every_hours'))
            ->get();

        $fired = 0;
        foreach ($candidates as $n) {
            if (!$this->isDue($n, $today)) continue;
            $ctrl->fire($n);
            $fired++;
            $this->info("disparado #{$n->id} \"{$n->title}\" ({$n->recurrence}={$n->recurrence_value})");
        }
        $this->info("avisos recorrentes disparados: {$fired}");
        return self::SUCCESS;
    }

    /** O aviso deve disparar agora? */
    private function isDue(AppNotification $n, Carbon $today): bool
    {
        $v = (int) $n->recurrence_value;

        // Por HORAS: dispara quando passou o intervalo em horas (sem trava de 1x/dia).
        if ($n->recurrence === 'every_hours') {
            return !$n->last_fired_at || $n->last_fired_at->copy()->addHours(max(1, $v))->lte($today);
        }

        if ($n->last_fired_at && $n->last_fired_at->isSameDay($today)) return false; // 1x por dia

        return match ($n->recurrence) {
            'every_days'   => !$n->last_fired_at
                                || $n->last_fired_at->copy()->startOfDay()->addDays($v)->lte($today->copy()->startOfDay()),
            'day_of_month' => $today->day === min($v, $today->daysInMonth), // dia 31 em mês curto = último dia
            'business_day' => optional($this->nthBusinessDay($today, $v))->isSameDay($today) ?? false,
            default        => false,
        };
    }

    /** Data do Nº dia útil (seg-sex) do mês de $ref; null se não existir. */
    private function nthBusinessDay(Carbon $ref, int $n): ?Carbon
    {
        $d = $ref->copy()->startOfMonth();
        $count = 0;
        while ($d->month === $ref->month) {
            if (!$d->isWeekend()) {
                $count++;
                if ($count === $n) return $d->copy();
            }
            $d->addDay();
        }
        return null;
    }
}
