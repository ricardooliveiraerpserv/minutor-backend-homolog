<?php

namespace App\Services;

use App\Models\ClosingLog;
use App\Models\Holiday;
use App\Models\ProjectOpenPeriod;
use App\Models\User;
use App\Models\WeekOpenPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fonte ÚNICA das regras de fechamento de horas (mensal + semanal).
 *
 * MENSAL: prazo = 2º dia útil do mês SEGUINTE, 23:59 SP (mantém a regra existente).
 * SEMANAL: semana = segunda→domingo; prazo = 2º dia útil da semana SEGUINTE, 23:59 SP.
 * Ambas COEXISTEM (não se sobrepõem): um apontamento é bloqueado/atrasado se QUALQUER
 * uma das duas estiver fechada. Reabertura (mês/semana, global/projeto) auto-fecha às
 * 23:59 do dia da reabertura.
 */
class ClosingService
{
    private const TZ = 'America/Sao_Paulo';

    // ── Datas / prazos ────────────────────────────────────────────────────────

    /** Segunda-feira (00:00 SP) da semana da data informada. */
    public function weekStart(string $date): Carbon
    {
        return Carbon::parse($date, self::TZ)->startOfDay()->startOfWeek(Carbon::MONDAY);
    }

    /** 2º dia útil (pula fim de semana + feriados ativos) a partir de $from, às 23:59:59 SP. */
    private function secondBusinessDayDeadline(Carbon $from): Carbon
    {
        $cursor   = $from->copy()->startOfDay();
        $feriados = $this->holidaysAround($cursor);
        $uteis    = 0;
        while (true) {
            if (!$cursor->isWeekend() && !in_array($cursor->toDateString(), $feriados, true)) {
                if (++$uteis === 2) break;
            }
            $cursor->addDay();
        }
        return $cursor->setTime(23, 59, 59);
    }

    /** Feriados ativos no intervalo [$from - 5d, $from + 15d] (cobre a busca do 2º dia útil). */
    private function holidaysAround(Carbon $from): array
    {
        return Holiday::whereBetween('date', [$from->copy()->subDays(5)->toDateString(), $from->copy()->addDays(15)->toDateString()])
            ->where('active', true)->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
    }

    /** Prazo mensal: 2º dia útil do mês SEGUINTE a $ym, 23:59 SP. */
    public function monthDeadline(string $ym): Carbon
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $next = Carbon::create($y, $m, 1, 0, 0, 0, self::TZ)->addMonthNoOverflow();
        return $this->secondBusinessDayDeadline($next);
    }

    /** Prazo semanal: 2º dia útil da semana SEGUINTE (segunda seguinte), 23:59 SP. */
    public function weekDeadline(Carbon $weekStart): Carbon
    {
        return $this->secondBusinessDayDeadline($weekStart->copy()->addWeek());
    }

    /** Marco "daqui pra frente": semanas com prazo < isso nunca fecham pela regra semanal. */
    public function weeklyActivatedAt(): Carbon
    {
        $at = ClosingLog::where('event', 'activation')->min('occurred_at');
        return $at ? Carbon::parse($at) : Carbon::now(self::TZ);
    }

    // ── Está fechado? ─────────────────────────────────────────────────────────

    /** MENSAL: prazo venceu e sem reabertura ativa (mês) do projeto. */
    public function isMonthClosed(string $date, int $projectId): bool
    {
        $ym = Carbon::parse($date, self::TZ)->format('Y-m');
        if (Carbon::now(self::TZ)->lte($this->monthDeadline($ym))) return false;

        // auto_close_at é gravado como instante UTC → comparar com now() (tz do app = UTC).
        $reaberto = ProjectOpenPeriod::where('project_id', $projectId)
            ->where('year_month', $ym)
            ->whereNull('closed_at')
            ->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now()))
            ->exists();
        return !$reaberto;
    }

    /** SEMANAL: prazo venceu (e >= ativação) e sem reabertura ativa (global OU do projeto). */
    public function isWeekClosed(string $date, int $projectId): bool
    {
        $weekStart = $this->weekStart($date);
        $deadline  = $this->weekDeadline($weekStart);
        $now       = Carbon::now(self::TZ);

        if ($now->lte($deadline)) return false;                 // dentro do prazo
        if ($deadline->lt($this->weeklyActivatedAt())) return false; // grandfather (daqui pra frente)

        $reaberto = WeekOpenPeriod::where('week_start', $weekStart->toDateString())
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId)) // global OU do projeto
            ->whereNull('closed_at')
            ->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now())) // UTC
            ->exists();
        return !$reaberto;
    }

    /** Bloqueio COMBINADO usado na integração e no lançamento manual. */
    public function isPeriodClosed(string $date, int $projectId): bool
    {
        return $this->isMonthClosed($date, $projectId) || $this->isWeekClosed($date, $projectId);
    }

    // ── Reabertura ────────────────────────────────────────────────────────────

    /** Instante (UTC) equivalente às 23:59:59 SP de HOJE — fim automático da reabertura. */
    private function autoCloseToday(): Carbon
    {
        return Carbon::now(self::TZ)->setTime(23, 59, 59)->setTimezone('UTC');
    }

    /** Reabre a semana (global se $projectId null; senão só do projeto) até 23:59 de hoje. */
    public function reopenWeek(Carbon $weekStart, ?int $projectId, User $user): WeekOpenPeriod
    {
        $period = WeekOpenPeriod::updateOrCreate(
            ['project_id' => $projectId, 'week_start' => $weekStart->toDateString()],
            ['opened_by' => $user->id, 'closed_by' => null, 'closed_at' => null, 'auto_close_at' => $this->autoCloseToday()]
        );
        $this->log('week_reopen', 'week', $this->weekDeadline($weekStart)->toDateString(), $projectId, $user->id,
            ($projectId ? "Semana reaberta (projeto {$projectId})" : 'Semana reaberta (global)') . ' até 23:59');
        return $period;
    }

    public function log(string $event, string $kind, string $key, ?int $projectId, ?int $userId, ?string $note = null): void
    {
        ClosingLog::create([
            'event' => $event, 'period_kind' => $kind, 'period_key' => $key,
            'project_id' => $projectId, 'user_id' => $userId,
            'occurred_at' => Carbon::now(self::TZ), 'note' => $note,
        ]);
    }

    /** Log idempotente (scheduler): grava só se ainda não existe o mesmo evento/período/projeto. */
    public function logOnce(string $event, string $kind, string $key, ?int $projectId, ?string $note = null): bool
    {
        $exists = ClosingLog::where('event', $event)->where('period_kind', $kind)
            ->where('period_key', $key)
            ->where(fn ($q) => $projectId === null ? $q->whereNull('project_id') : $q->where('project_id', $projectId))
            ->exists();
        if ($exists) return false;
        $this->log($event, $kind, $key, $projectId, null, $note);
        return true;
    }
}
