<?php

namespace App\Services;

use App\Models\ClosingLog;
use App\Models\CompetenceClosure;
use App\Models\FechamentoAdministrativo;
use App\Models\Holiday;
use App\Models\ProjectOpenPeriod;
use App\Models\User;
use App\Models\WeekOpenPeriod;
use Carbon\Carbon;

/**
 * Fonte ÚNICA das regras de abertura/fechamento de horas (mensal + semanal).
 *
 * MENSAL: prazo = 1º dia útil do mês SEGUINTE, 23:59 SP. SEMANAL: semana = segunda→domingo;
 * prazo = 1º dia útil da semana SEGUINTE, 23:59 SP. COEXISTEM: bloqueia se mês OU semana fechada.
 *
 * Reabertura/encerramento têm ESCOPO: projeto (null=global) + usuário (null=todos). Reabertura
 * auto-fecha às 23:59 do dia. Encerramento (CompetenceClosure) fecha o período ANTES do prazo.
 * Reabrir o MÊS libera também as SEMANAS do mês (mesmo escopo/usuário).
 */
class ClosingService
{
    private const TZ = 'America/Sao_Paulo';

    // ── Datas / prazos ────────────────────────────────────────────────────────

    public function weekStart(string $date): Carbon
    {
        return Carbon::parse($date, self::TZ)->startOfDay()->startOfWeek(Carbon::MONDAY);
    }

    /** Mês (Y-m) ao qual a semana pertence — o da SEGUNDA-feira. */
    public function weekMonth(Carbon $weekStart): string
    {
        return $weekStart->format('Y-m');
    }

    /** 1º dia útil (pula fim de semana + feriados ativos) a partir de $from, às 23:59:59 SP. */
    private function firstBusinessDayDeadline(Carbon $from): Carbon
    {
        $cursor   = $from->copy()->startOfDay();
        $feriados = $this->holidaysAround($cursor);
        while ($cursor->isWeekend() || in_array($cursor->toDateString(), $feriados, true)) {
            $cursor->addDay();
        }
        return $cursor->setTime(23, 59, 59);
    }

    /** 2o dia util a partir de $from, as 23:59:59. Prazo de digitacao da competencia
     *  = 2o dia util da semana/mes seguinte (regra do fechamento). */
    private function secondBusinessDayDeadline(Carbon $from): Carbon
    {
        $cursor   = $from->copy()->startOfDay();
        $feriados = $this->holidaysAround($cursor);
        $uteis    = 0;
        while (true) {
            if (!$cursor->isWeekend() && !in_array($cursor->toDateString(), $feriados, true)) {
                if (++$uteis === 2) {
                    break;
                }
            }
            $cursor->addDay();
        }
        return $cursor->setTime(23, 59, 59);
    }

    private function holidaysAround(Carbon $from): array
    {
        return Holiday::whereBetween('date', [$from->copy()->subDays(5)->toDateString(), $from->copy()->addDays(15)->toDateString()])
            ->where('active', true)->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
    }

    public function monthDeadline(string $ym): Carbon
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $next = Carbon::create($y, $m, 1, 0, 0, 0, self::TZ)->addMonthNoOverflow();
        return $this->secondBusinessDayDeadline($next);
    }

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

    // ── Escopo (projeto null=global; usuário null=todos) ──────────────────────

    private function scoped($query, ?int $projectId, ?int $userId)
    {
        return $query
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId));
    }

    private function activeMonthReopen(string $ym, ?int $projectId, ?int $userId): bool
    {
        return $this->scoped(
            ProjectOpenPeriod::where('year_month', $ym)->whereNull('closed_at')
                ->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now())),
            $projectId, $userId
        )->exists();
    }

    private function activeWeekReopen(string $weekStartDate, ?int $projectId, ?int $userId): bool
    {
        return $this->scoped(
            WeekOpenPeriod::where('week_start', $weekStartDate)->whereNull('closed_at')
                ->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now())),
            $projectId, $userId
        )->exists();
    }

    private function hasClosure(string $kind, string $key, ?int $projectId, ?int $userId): bool
    {
        return $this->scoped(
            CompetenceClosure::where('period_kind', $kind)->where('period_key', $key),
            $projectId, $userId
        )->exists();
    }

    private function adminMonthClosed(string $ym): bool
    {
        return (bool) FechamentoAdministrativo::where('year_month', $ym)->first()?->isClosed();
    }

    // ── Está fechado? (com escopo de usuário) ─────────────────────────────────

    /**
     * MENSAL fechado. $forIntegration=true → prazo do 1º dia útil conta SEM grandfather
     * (preserva a regra de ATRASO da integração, que sempre existiu). No lançamento manual
     * (false) o prazo mensal só conta "daqui pra frente" (>= ativação) — antes o manual só
     * era bloqueado pelo Fechamento Administrativo, então não regride meses antigos.
     */
    public function isMonthClosed(string $date, int $projectId, ?int $userId = null, bool $forIntegration = false): bool
    {
        $ym       = Carbon::parse($date, self::TZ)->format('Y-m');
        $deadline = $this->monthDeadline($ym);
        $deadlineCounts = Carbon::now(self::TZ)->gt($deadline)
            && ($forIntegration || $deadline->gte($this->weeklyActivatedAt()));
        $closedReason = $deadlineCounts
            || $this->adminMonthClosed($ym)
            || $this->hasClosure('month', $ym, $projectId, $userId);
        if (!$closedReason) return false;
        return !$this->activeMonthReopen($ym, $projectId, $userId);
    }

    public function isWeekClosed(string $date, int $projectId, ?int $userId = null): bool
    {
        $weekStart = $this->weekStart($date);
        $deadline  = $this->weekDeadline($weekStart);
        $now       = Carbon::now(self::TZ);
        $wsDate    = $weekStart->toDateString();

        $deadlinePassed = $now->gt($deadline) && $deadline->gte($this->weeklyActivatedAt());
        $closedReason   = $deadlinePassed || $this->hasClosure('week', $wsDate, $projectId, $userId);
        if (!$closedReason) return false;

        // Reabertura da SEMANA ou reabertura do MÊS (libera o mês inteiro) abrem a semana.
        return !($this->activeWeekReopen($wsDate, $projectId, $userId)
            || $this->activeMonthReopen($this->weekMonth($weekStart), $projectId, $userId));
    }

    /** Bloqueio COMBINADO (integração + lançamento manual). $userId = quem apontou. */
    public function isPeriodClosed(string $date, int $projectId, ?int $userId = null, bool $forIntegration = false): bool
    {
        return $this->isMonthClosed($date, $projectId, $userId, $forIntegration) || $this->isWeekClosed($date, $projectId, $userId);
    }

    // ── Status para o painel (visão GLOBAL: project null + user null) ─────────

    /** @return array{status:string, auto_close_at:?string, deadline:string} */
    public function weekStatusGlobal(Carbon $weekStart): array
    {
        $ws       = $weekStart->toDateString();
        $deadline = $this->weekDeadline($weekStart);
        $past     = Carbon::now(self::TZ)->gt($deadline) && $deadline->gte($this->weeklyActivatedAt());

        $reopen = WeekOpenPeriod::where('week_start', $ws)->whereNull('project_id')->whereNull('user_id')
            ->whereNull('closed_at')->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now()))->first();
        $monthReopen = ProjectOpenPeriod::where('year_month', $this->weekMonth($weekStart))->whereNull('project_id')->whereNull('user_id')
            ->whereNull('closed_at')->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now()))->first();
        $closure = CompetenceClosure::where('period_kind', 'week')->where('period_key', $ws)->whereNull('project_id')->whereNull('user_id')->exists();

        $active = $reopen ?: $monthReopen;
        $status = $active ? 'reaberta' : (($past || $closure) ? 'fechada' : 'aberta');
        return ['status' => $status, 'auto_close_at' => optional($active?->auto_close_at)->toIso8601String(), 'deadline' => $deadline->toIso8601String()];
    }

    /** @return array{status:string, auto_close_at:?string, deadline:string} */
    public function monthStatusGlobal(string $ym): array
    {
        $deadline = $this->monthDeadline($ym);
        // Painel reflete o bloqueio MANUAL: prazo mensal só "fecha" daqui pra frente (>= ativação).
        $past     = Carbon::now(self::TZ)->gt($deadline) && $deadline->gte($this->weeklyActivatedAt());
        $reopen   = ProjectOpenPeriod::where('year_month', $ym)->whereNull('project_id')->whereNull('user_id')
            ->whereNull('closed_at')->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now()))->first();
        $closure  = CompetenceClosure::where('period_kind', 'month')->where('period_key', $ym)->whereNull('project_id')->whereNull('user_id')->exists();

        $status = $reopen ? 'reaberta' : (($past || $this->adminMonthClosed($ym) || $closure) ? 'fechada' : 'aberta');
        return ['status' => $status, 'auto_close_at' => optional($reopen?->auto_close_at)->toIso8601String(), 'deadline' => $deadline->toIso8601String()];
    }

    // ── Reabertura / Encerramento ─────────────────────────────────────────────

    private function autoCloseToday(): Carbon
    {
        return Carbon::now(self::TZ)->setTime(23, 59, 59)->setTimezone('UTC');
    }

    private function scopeNote(?int $projectId, ?int $userId): string
    {
        $parts = [];
        $parts[] = $projectId ? "projeto {$projectId}" : 'global';
        if ($userId) $parts[] = "usuário {$userId}";
        return implode(', ', $parts);
    }

    public function reopenWeek(Carbon $weekStart, ?int $projectId, ?int $userId, User $user): WeekOpenPeriod
    {
        // Reabrir CANCELA o encerramento manual (antecipação) do mesmo escopo → volta a
        // valer o PRAZO natural da semana. A reabertura temporária (auto-fecha 23:59) ainda
        // é gravada p/ cobrir o caso de semana já vencida por prazo.
        $this->clearClosure('week', $weekStart->toDateString(), $projectId, $userId);
        $period = WeekOpenPeriod::updateOrCreate(
            ['project_id' => $projectId, 'user_id' => $userId, 'week_start' => $weekStart->toDateString()],
            ['opened_by' => $user->id, 'closed_by' => null, 'closed_at' => null, 'auto_close_at' => $this->autoCloseToday()]
        );
        $this->log('week_reopen', 'week', $weekStart->toDateString(), $projectId, $user->id,
            'Semana reaberta (' . $this->scopeNote($projectId, $userId) . ') — volta a valer o prazo');
        return $period;
    }

    public function reopenMonth(string $ym, ?int $projectId, ?int $userId, User $user): ProjectOpenPeriod
    {
        // Reabrir CANCELA o encerramento manual (antecipação) do mesmo escopo → volta o prazo.
        $this->clearClosure('month', $ym, $projectId, $userId);
        $period = ProjectOpenPeriod::updateOrCreate(
            ['project_id' => $projectId, 'user_id' => $userId, 'year_month' => $ym],
            ['opened_by' => $user->id, 'closed_by' => null, 'closed_at' => null, 'auto_close_at' => $this->autoCloseToday()]
        );
        $this->log('month_reopen', 'month', $ym, $projectId, $user->id,
            'Competência reaberta (' . $this->scopeNote($projectId, $userId) . ') — volta a valer o prazo');
        return $period;
    }

    /**
     * Remove o encerramento manual (CompetenceClosure) do escopo EXATO reaberto. Match exato
     * (não o guarda-chuva do scoped): reabrir global tira só o closure global; reabrir um
     * projeto/usuário tira só o dele — sem apagar encerramentos de outros escopos.
     */
    private function clearClosure(string $kind, string $key, ?int $projectId, ?int $userId): void
    {
        CompetenceClosure::where('period_kind', $kind)->where('period_key', $key)
            ->where(fn ($q) => $projectId === null ? $q->whereNull('project_id') : $q->where('project_id', $projectId))
            ->where(fn ($q) => $userId === null ? $q->whereNull('user_id') : $q->where('user_id', $userId))
            ->delete();
    }

    /** Encerra a SEMANA já (fecha reabertura ativa do escopo + grava closure). */
    public function closeWeek(Carbon $weekStart, ?int $projectId, ?int $userId, User $user): void
    {
        $ws = $weekStart->toDateString();
        $this->scoped(WeekOpenPeriod::where('week_start', $ws)->whereNull('closed_at'), $projectId, $userId)
            ->update(['closed_at' => now(), 'closed_by' => $user->id]);
        CompetenceClosure::updateOrCreate(
            ['period_kind' => 'week', 'period_key' => $ws, 'project_id' => $projectId, 'user_id' => $userId],
            ['closed_by' => $user->id, 'closed_at' => now()]
        );
        $this->log('week_manual_close', 'week', $ws, $projectId, $user->id,
            'Semana encerrada (' . $this->scopeNote($projectId, $userId) . ')');
    }

    /** Encerra o MÊS já (fecha reabertura ativa do escopo + grava closure). */
    public function closeMonth(string $ym, ?int $projectId, ?int $userId, User $user): void
    {
        $this->scoped(ProjectOpenPeriod::where('year_month', $ym)->whereNull('closed_at'), $projectId, $userId)
            ->update(['closed_at' => now(), 'closed_by' => $user->id]);
        CompetenceClosure::updateOrCreate(
            ['period_kind' => 'month', 'period_key' => $ym, 'project_id' => $projectId, 'user_id' => $userId],
            ['closed_by' => $user->id, 'closed_at' => now()]
        );
        $this->log('month_manual_close', 'month', $ym, $projectId, $user->id,
            'Competência encerrada (' . $this->scopeNote($projectId, $userId) . ')');
    }

    // ── Log ───────────────────────────────────────────────────────────────────

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
