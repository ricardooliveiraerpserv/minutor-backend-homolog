<?php

namespace App\Services;

use App\Models\ConsultantHourBankClosing;
use App\Models\Holiday;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class HourBankService
{
    // ─── Cálculo de Dias Úteis ──────────────────────────────────────────────

    /**
     * Calcula dias úteis do mês, excluindo fins de semana e feriados em dias úteis.
     * Feriados que caem em FDS não são descontados novamente.
     */
    public function calculateWorkingDays(int $year, int $month): array
    {
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate   = $startDate->copy()->endOfMonth();

        // Busca feriados ativos do mês (qualquer tipo)
        $holidayDates = Holiday::whereYear('date', $year)
            ->whereMonth('date', $month)
            ->where('active', true)
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->toArray();

        $workingDays   = 0;
        $holidaysCount = 0;

        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            $isWeekend = $current->isWeekend();
            $isHoliday = in_array($current->format('Y-m-d'), $holidayDates);

            if (!$isWeekend) {
                if ($isHoliday) {
                    $holidaysCount++; // feriado em dia útil
                } else {
                    $workingDays++;
                }
            }
            // feriado em FDS: não conta (não desconta dia útil)

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
     */
    public function getWorkedHours(int $userId, int $year, int $month): float
    {
        $minutes = Timesheet::where('user_id', $userId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->whereIn('status', ['approved', 'pending'])
            ->sum('effort_minutes');

        return round($minutes / 60, 2);
    }

    // ─── Saldo Anterior ────────────────────────────────────────────────────

    /**
     * Retorna o SaldoFinal do mês anterior (a partir do snapshot fechado/aberto).
     * Se não existir registro, retorna 0.
     */
    public function getPreviousBalance(int $userId, int $year, int $month): float
    {
        $prevDate      = Carbon::createFromDate($year, $month, 1)->subMonth();
        $prevYearMonth = $prevDate->format('Y-m');

        $closing = ConsultantHourBankClosing::where('user_id', $userId)
            ->where('year_month', $prevYearMonth)
            ->first();

        return $closing ? (float) $closing->final_balance : 0.0;
    }

    // ─── Cálculo Central ───────────────────────────────────────────────────

    /**
     * Calcula todos os campos do mês dinamicamente.
     * Pode ser usado tanto para preview (mês aberto) quanto para fechar.
     */
    public function calculateMonth(
        int   $userId,
        int   $year,
        int   $month,
        float $dailyHours = 8.0
    ): array {
        $workingData  = $this->calculateWorkingDays($year, $month);
        $expectedHours = round($workingData['working_days'] * $dailyHours, 2);
        $workedHours   = $this->getWorkedHours($userId, $year, $month);
        $previousBalance = $this->getPreviousBalance($userId, $year, $month);

        $monthBalance    = round($workedHours - $expectedHours, 2);
        $accumulated     = round($previousBalance + $monthBalance, 2);

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
            'working_days'        => $workingData['working_days'],
            'holidays_count'      => $workingData['holidays_count'],
            'expected_hours'      => $expectedHours,
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
        int    $userId,
        string $yearMonth,
        int    $closedBy,
        float  $dailyHours = 8.0,
        ?string $notes = null
    ): ConsultantHourBankClosing {
        [$year, $month] = array_map('intval', explode('-', $yearMonth));

        // Verificar se já está fechado
        $existing = ConsultantHourBankClosing::where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($existing && $existing->isClosed()) {
            throw new \Exception("O mês {$yearMonth} já está fechado e não pode ser recalculado.");
        }

        $data = $this->calculateMonth($userId, $year, $month, $dailyHours);

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
     * Retorna o histórico de fechamentos de um consultor, do mais recente ao mais antigo.
     * Meses fechados → dados do snapshot. Mês atual aberto → cálculo dinâmico.
     */
    public function getHistory(int $userId, int $limit = 24): Collection
    {
        return ConsultantHourBankClosing::where('user_id', $userId)
            ->orderBy('year_month', 'desc')
            ->limit($limit)
            ->with(['closedByUser:id,name'])
            ->get();
    }

    // ─── Preview do Mês Atual ─────────────────────────────────────────────

    /**
     * Retorna o cálculo do mês atual dinamicamente (sem persistir).
     * Se já houver registro aberto, retorna os dados atualizados.
     * Se já houver registro fechado, retorna o snapshot.
     */
    public function previewCurrentMonth(int $userId, float $dailyHours = 8.0): array
    {
        $now        = Carbon::now();
        $yearMonth  = $now->format('Y-m');
        $year       = (int) $now->year;
        $month      = (int) $now->month;

        $existing = ConsultantHourBankClosing::where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->first();

        // Se fechado, retorna o snapshot
        if ($existing && $existing->isClosed()) {
            return $existing->toArray();
        }

        // Calcula dinamicamente
        return $this->calculateMonth($userId, $year, $month, $dailyHours);
    }

    // ─── Preview de Mês Específico ─────────────────────────────────────────

    public function previewMonth(int $userId, string $yearMonth, float $dailyHours = 8.0): array
    {
        [$year, $month] = array_map('intval', explode('-', $yearMonth));

        $existing = ConsultantHourBankClosing::where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($existing && $existing->isClosed()) {
            return $existing->toArray();
        }

        return $this->calculateMonth($userId, $year, $month, $dailyHours);
    }
}
