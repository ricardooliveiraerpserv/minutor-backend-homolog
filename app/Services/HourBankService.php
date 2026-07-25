<?php

namespace App\Services;

use App\Models\ConsultantHourBankClosing;
use App\Models\Holiday;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class HourBankService
{
    /** Cache do saldo inicial (banco de horas) por usuário nesta request. */
    private array $initialBalanceCache = [];

    /**
     * Saldo inicial do banco de horas trazido de outro sistema (ex.: -100h devedor).
     * Semeia o cálculo no 1º mês (mês de bank_hours_start_date). 0 quando não informado.
     */
    private function initialBalanceFor(int $userId): float
    {
        if (!array_key_exists($userId, $this->initialBalanceCache)) {
            $this->initialBalanceCache[$userId] = (float) (
                \App\Models\User::where('id', $userId)->value('bank_hours_initial_balance') ?? 0
            );
        }
        return $this->initialBalanceCache[$userId];
    }

    // ─── Cálculo de Dias Úteis ──────────────────────────────────────────────

    /**
     * Calcula dias úteis do mês, excluindo fins de semana e feriados em dias úteis.
     * Se $startDate for fornecido e cair no mesmo mês/ano, conta apenas a partir dessa data.
     */
    public function calculateWorkingDays(int $year, int $month, ?string $startDate = null, ?string $upToDate = null): array
    {
        $monthStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $monthEnd   = $monthStart->copy()->endOfMonth();

        // Limita o fim do intervalo (ex.: HOJE) — conta só os dias úteis DECORRIDOS. Usado no mês
        // corrente pra o saldo não nascer -184h no início do mês (previsto vs. mês inteiro).
        if ($upToDate) {
            $ud = Carbon::parse($upToDate)->endOfDay();
            if ($ud->lt($monthEnd)) $monthEnd = $ud;
        }

        // Se existe data de início, ajusta o começo do intervalo
        $rangeStart = $monthStart->copy();
        if ($startDate) {
            $sd = Carbon::parse($startDate)->startOfDay();
            // Só aplica restrição se a data de início cai neste mesmo mês
            if ($sd->year === $year && $sd->month === $month && $sd->gt($rangeStart)) {
                $rangeStart = $sd;
            }
        }

        // Busca feriados ativos do mês
        $holidayDates = Holiday::whereYear('date', $year)
            ->whereMonth('date', $month)
            ->whereRaw('"active" = true')
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->toArray();

        $workingDays   = 0;
        $holidaysCount = 0;

        $current = $rangeStart->copy();
        while ($current->lte($monthEnd)) {
            $isWeekend = $current->isWeekend();
            $isHoliday = in_array($current->format('Y-m-d'), $holidayDates);

            if (!$isWeekend) {
                if ($isHoliday) {
                    $holidaysCount++;
                } else {
                    $workingDays++;
                }
            }

            $current->addDay();
        }

        return [
            'working_days'   => $workingDays,
            'holidays_count' => $holidaysCount,
        ];
    }

    // ─── Horas Trabalhadas ──────────────────────────────────────────────────

    /**
     * Soma horas apontadas (approved + pending) no mês para o consultor.
     * Se $startDate cair no mesmo mês, só conta apontamentos a partir dessa data.
     */
    public function getWorkedHours(int $userId, int $year, int $month, ?string $startDate = null): float
    {
        $query = Timesheet::where('user_id', $userId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->where('is_billable_only', false)
            ->where('is_internal_action', false)
            ->whereIn('status', ['approved', 'pending']);

        if ($startDate) {
            $sd = Carbon::parse($startDate);
            if ($sd->year === $year && $sd->month === $month) {
                $query->where('date', '>=', $sd->format('Y-m-d'));
            }
        }

        $minutes = $query->sum('effort_minutes');
        return round($minutes / 60, 2);
    }

    // ─── Saldo Anterior ────────────────────────────────────────────────────

    /**
     * Retorna o SaldoFinal do mês anterior.
     * Se o mês anterior for antes de $startDate, retorna 0 (não há histórico antes do início).
     */
    public function getPreviousBalance(int $userId, int $year, int $month, ?string $startDate = null): float
    {
        $prevDate      = Carbon::createFromDate($year, $month, 1)->subMonth();
        $prevYearMonth = $prevDate->format('Y-m');

        // Antes do início do banco não há histórico no Minutor — mas o consultor pode
        // trazer um SALDO INICIAL de outro sistema (ex.: -100h). Ele semeia o 1º mês.
        if ($startDate) {
            $startYearMonth = Carbon::parse($startDate)->format('Y-m');
            if ($prevYearMonth < $startYearMonth) {
                return $this->initialBalanceFor($userId);
            }
        }

        $closing = ConsultantHourBankClosing::where('user_id', $userId)
            ->where('year_month', $prevYearMonth)
            ->first();
        if ($closing) {
            return (float) $closing->final_balance;
        }

        // Sem fechamento GRAVADO do mês anterior: encadeia DINAMICAMENTE recalculando o mês
        // anterior (que por sua vez recua até o mês de início, onde o saldo inicial semeia).
        // Sem isto, a virada de um mês ainda não fechado ZERAVA o saldo — fazendo o saldo
        // inicial (ex.: -250h) sumir e gerar horas extras indevidas no mês seguinte.
        // Guardado por $startDate (recursão termina no mês de início) para não recorrer infinito.
        if ($startDate) {
            $dailyHours = (float) (\App\Models\User::where('id', $userId)->value('daily_hours') ?? 8.0);
            $prev = $this->calculateMonth($userId, (int) $prevDate->year, (int) $prevDate->month, $dailyHours, $startDate);
            return (float) ($prev['final_balance'] ?? 0.0);
        }

        return 0.0;
    }

    // ─── Cálculo Central ───────────────────────────────────────────────────

    /**
     * Calcula todos os campos do mês dinamicamente.
     * $startDate limita cálculos ao período após o início do banco de horas.
     */
    public function calculateMonth(
        int     $userId,
        int     $year,
        int     $month,
        float   $dailyHours = 8.0,
        ?string $startDate  = null,
        float   $additionalWorkedHours = 0.0
    ): array {
        // Dias úteis do MÊS INTEIRO (info: "quantas horas úteis o mês terá").
        $workingFull     = $this->calculateWorkingDays($year, $month, $startDate);
        $expectedFull    = round($workingFull['working_days'] * $dailyHours, 2);

        // No MÊS CORRENTE o saldo usa só os dias úteis DECORRIDOS (até hoje) — senão o mês nasce
        // com déficit do mês inteiro (-184h). Meses passados usam o mês inteiro (já completos).
        $isCurrent       = ((int) now()->year === $year && (int) now()->month === $month);
        $workingUsed     = $isCurrent
            ? $this->calculateWorkingDays($year, $month, $startDate, now()->toDateString())
            : $workingFull;
        $expectedHours   = round($workingUsed['working_days'] * $dailyHours, 2);

        $workedHours     = round($this->getWorkedHours($userId, $year, $month, $startDate) + $additionalWorkedHours, 2);
        $previousBalance = $this->getPreviousBalance($userId, $year, $month, $startDate);

        $monthBalance = round($workedHours - $expectedHours, 2);
        $accumulated  = round($previousBalance + $monthBalance, 2);

        // Regra de pagamento
        if ($accumulated > 0) {
            $paidHours    = $accumulated;
            $finalBalance = 0.0;
        } else {
            $paidHours    = 0.0;
            $finalBalance = $accumulated;
        }

        return [
            'user_id'             => $userId,
            'year_month'          => sprintf('%04d-%02d', $year, $month),
            'daily_hours'         => $dailyHours,
            'working_days'        => $workingUsed['working_days'],
            'holidays_count'      => $workingUsed['holidays_count'],
            'expected_hours'      => $expectedHours,
            // Mês inteiro (info "horas úteis do mês") + flag de mês corrente pro FE mostrar o "até hoje".
            'working_days_full'   => $workingFull['working_days'],
            'expected_hours_full' => $expectedFull,
            'is_current_month'    => $isCurrent,
            'worked_hours'        => $workedHours,
            'month_balance'       => $monthBalance,
            'previous_balance'    => round($previousBalance, 2),
            'accumulated_balance' => $accumulated,
            'paid_hours'          => round($paidHours, 2),
            'final_balance'       => round($finalBalance, 2),
            'status'              => 'open',
        ];
    }

    // ─── Fechamento (Snapshot Imutável) ────────────────────────────────────

    /**
     * Fecha o mês: persiste o snapshot. Se já existir registro, atualiza apenas se estiver aberto.
     *
     * @throws \Exception se o mês já estiver fechado
     */
    public function closeMonth(
        int     $userId,
        string  $yearMonth,
        int     $closedBy,
        float   $dailyHours = 8.0,
        ?string $notes      = null,
        ?string $startDate  = null
    ): ConsultantHourBankClosing {
        [$year, $month] = array_map('intval', explode('-', $yearMonth));

        $existing = ConsultantHourBankClosing::where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($existing && $existing->isClosed()) {
            throw new \Exception("O mês {$yearMonth} já está fechado e não pode ser recalculado.");
        }

        $data = $this->calculateMonth($userId, $year, $month, $dailyHours, $startDate);

        $closing = ConsultantHourBankClosing::updateOrCreate(
            ['user_id' => $userId, 'year_month' => $yearMonth],
            array_merge($data, [
                'status'    => 'closed',
                'closed_at' => now(),
                'closed_by' => $closedBy,
                'notes'     => $notes,
            ])
        );

        return $closing->fresh(['user', 'closedByUser']);
    }

    /**
     * Reabre um mês fechado (ação controlada/administrativa).
     *
     * @throws \Exception se o mês não estiver fechado
     */
    public function reopenMonth(int $userId, string $yearMonth): ConsultantHourBankClosing
    {
        $closing = ConsultantHourBankClosing::where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->firstOrFail();

        if ($closing->isOpen()) {
            throw new \Exception("O mês {$yearMonth} já está aberto.");
        }

        $closing->update([
            'status'    => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ]);

        return $closing->fresh();
    }

    // ─── Histórico Completo ────────────────────────────────────────────────

    /**
     * Retorna o histórico de fechamentos, do mais recente ao mais antigo.
     * Se $startDate for fornecido, ignora meses anteriores à data de início.
     */
    public function getHistory(int $userId, int $limit = 24, ?string $startDate = null): Collection
    {
        $query = ConsultantHourBankClosing::where('user_id', $userId)
            ->orderBy('year_month', 'desc')
            ->limit($limit)
            ->with(['closedByUser:id,name']);

        if ($startDate) {
            $query->where('year_month', '>=', Carbon::parse($startDate)->format('Y-m'));
        }

        return $query->get();
    }

    // ─── Cálculo em Cadeia (sem closings armazenados) ────────────────────

    /**
     * Calcula todos os meses de $fromYearMonth até $toYearMonth em sequência,
     * encadeando o saldo final de cada mês como saldo anterior do próximo.
     * Retorna array indexado por 'YYYY-MM', do mais antigo ao mais recente.
     */
    public function calculateRange(
        int     $userId,
        string  $fromYearMonth,
        string  $toYearMonth,
        float   $dailyHours = 8.0,
        ?string $startDate  = null
    ): array {
        $results         = [];

        $current = Carbon::parse($fromYearMonth . '-01');
        $end     = Carbon::parse($toYearMonth   . '-01');

        // Semeia o encadeamento com o saldo anterior do 1º mês do range (que é o mês de
        // início do banco): traz o SALDO INICIAL de outro sistema quando houver.
        $prevFinalBalance = $this->getPreviousBalance($userId, (int) $current->year, (int) $current->month, $startDate);

        while ($current->lte($end)) {
            $year      = (int) $current->year;
            $month     = (int) $current->month;
            $yearMonth = $current->format('Y-m');

            $workingFull   = $this->calculateWorkingDays($year, $month, $startDate);
            $expectedFull  = round($workingFull['working_days'] * $dailyHours, 2);
            // Mês corrente: saldo pelos dias úteis DECORRIDOS (até hoje); passados = mês inteiro.
            $isCurrent     = ((int) now()->year === $year && (int) now()->month === $month);
            $workingUsed   = $isCurrent
                ? $this->calculateWorkingDays($year, $month, $startDate, now()->toDateString())
                : $workingFull;
            $expectedHours = round($workingUsed['working_days'] * $dailyHours, 2);
            $workedHours   = $this->getWorkedHours($userId, $year, $month, $startDate);

            $monthBalance = round($workedHours - $expectedHours, 2);
            $accumulated  = round($prevFinalBalance + $monthBalance, 2);

            if ($accumulated > 0) {
                $paidHours    = $accumulated;
                $finalBalance = 0.0;
            } else {
                $paidHours    = 0.0;
                $finalBalance = $accumulated;
            }

            $results[$yearMonth] = [
                'user_id'             => $userId,
                'year_month'          => $yearMonth,
                'daily_hours'         => $dailyHours,
                'working_days'        => $workingUsed['working_days'],
                'holidays_count'      => $workingUsed['holidays_count'],
                'expected_hours'      => $expectedHours,
                'working_days_full'   => $workingFull['working_days'],
                'expected_hours_full' => $expectedFull,
                'is_current_month'    => $isCurrent,
                'worked_hours'        => $workedHours,
                'month_balance'       => $monthBalance,
                'previous_balance'    => round($prevFinalBalance, 2),
                'accumulated_balance' => $accumulated,
                'paid_hours'          => round($paidHours, 2),
                'final_balance'       => round($finalBalance, 2),
            ];

            $prevFinalBalance = $finalBalance;
            $current->addMonth();
        }

        return $results;
    }

    // ─── Preview do Mês Atual ─────────────────────────────────────────────

    /**
     * Retorna o cálculo do mês atual dinamicamente (sem persistir).
     */
    public function previewCurrentMonth(int $userId, float $dailyHours = 8.0, ?string $startDate = null): array
    {
        $now       = Carbon::now();
        $yearMonth = $now->format('Y-m');
        $year      = (int) $now->year;
        $month     = (int) $now->month;

        $existing = ConsultantHourBankClosing::where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($existing && $existing->isClosed()) {
            return $existing->toArray();
        }

        return $this->calculateMonth($userId, $year, $month, $dailyHours, $startDate);
    }

    // ─── Preview de Mês Específico ─────────────────────────────────────────

    public function previewMonth(int $userId, string $yearMonth, float $dailyHours = 8.0, ?string $startDate = null): array
    {
        [$year, $month] = array_map('intval', explode('-', $yearMonth));

        $existing = ConsultantHourBankClosing::where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($existing && $existing->isClosed()) {
            return $existing->toArray();
        }

        return $this->calculateMonth($userId, $year, $month, $dailyHours, $startDate);
    }
}
