<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Relógio de HORAS ÚTEIS para o SLA do Help Desk. Diferente do BusinessCalendarService
 * (grosseiro, anda em dias inteiros), este respeita JANELAS INTRA-DIA (ex.: 09:00–12:00 e
 * 13:00–18:00, com almoço), feriados e fuso. Tudo calculado no timezone da política.
 *
 * `windows`: mapa isoWeekday (1=Seg … 7=Dom) → lista de [inícioMin, fimMin] em minutos do dia.
 *            Ex.: [1 => [[540,720],[780,1080]], …, 6 => [], 7 => []]. Vazio = não atende.
 *            null/[] TOTAL → 24x7 (equivale a horas corridas — retrocompat com política sem calendário).
 * `holidays`: set de datas 'Y-m-d' (no timezone) que não contam.
 */
class SlaBusinessClock
{
    /** Guarda-chuva contra loop infinito (nº máx. de dias percorridos). */
    private const MAX_DAYS = 1500;

    /**
     * Avança `minutes` horas úteis a partir de `start`, retornando o instante de vencimento.
     * Se não houver janelas em nenhum dia (24x7), soma minutos corridos.
     */
    public function addBusinessMinutes(CarbonInterface $start, int $minutes, array $windows, array $holidays, string $tz): Carbon
    {
        $cursor = ($start instanceof Carbon ? $start->copy() : Carbon::parse($start))->setTimezone($tz);
        if ($minutes <= 0) {
            return $cursor;
        }
        if ($this->is24x7($windows)) {
            return $cursor->addMinutes($minutes);
        }

        $remaining = $minutes;
        for ($guard = 0; $guard < self::MAX_DAYS; $guard++) {
            foreach ($this->dayWindows($cursor, $windows, $holidays) as [$ws, $we]) {
                $winStart = $cursor->copy()->startOfDay()->addMinutes($ws);
                $winEnd   = $cursor->copy()->startOfDay()->addMinutes($we);
                if ($cursor->greaterThanOrEqualTo($winEnd)) {
                    continue; // janela já passou
                }
                $segStart = $cursor->greaterThan($winStart) ? $cursor->copy() : $winStart->copy();
                $avail = $segStart->diffInMinutes($winEnd);
                if ($remaining <= $avail) {
                    return $segStart->addMinutes($remaining);
                }
                $remaining -= $avail;
                $cursor = $winEnd->copy();
            }
            // Consumiu o dia → próximo dia 00:00.
            $cursor = $cursor->copy()->addDay()->startOfDay();
        }
        return $cursor; // fallback defensivo (não deve chegar aqui)
    }

    /** Minutos úteis decorridos no intervalo [start, end] (0 se end<=start). */
    public function businessMinutesBetween(CarbonInterface $start, CarbonInterface $end, array $windows, array $holidays, string $tz): int
    {
        $a = ($start instanceof Carbon ? $start->copy() : Carbon::parse($start))->setTimezone($tz);
        $b = ($end instanceof Carbon ? $end->copy() : Carbon::parse($end))->setTimezone($tz);
        if ($b->lessThanOrEqualTo($a)) {
            return 0;
        }
        if ($this->is24x7($windows)) {
            return (int) $a->diffInMinutes($b);
        }

        $total = 0;
        $cursor = $a->copy();
        for ($guard = 0; $guard < self::MAX_DAYS && $cursor->lessThan($b); $guard++) {
            foreach ($this->dayWindows($cursor, $windows, $holidays) as [$ws, $we]) {
                $winStart = $cursor->copy()->startOfDay()->addMinutes($ws);
                $winEnd   = $cursor->copy()->startOfDay()->addMinutes($we);
                $segStart = $a->greaterThan($winStart) && $cursor->isSameDay($a) ? $a->copy() : $winStart->copy();
                if ($cursor->greaterThan($segStart)) $segStart = $cursor->copy();
                $segEnd = $b->lessThan($winEnd) ? $b->copy() : $winEnd->copy();
                if ($segEnd->greaterThan($segStart)) {
                    $total += $segStart->diffInMinutes($segEnd);
                }
            }
            $cursor = $cursor->copy()->addDay()->startOfDay();
        }
        return (int) $total;
    }

    /** Janelas [inícioMin,fimMin] do dia do cursor (vazio se feriado / dia sem atendimento). */
    private function dayWindows(Carbon $cursor, array $windows, array $holidays): array
    {
        if (in_array($cursor->format('Y-m-d'), $holidays, true)) {
            return [];
        }
        return $windows[$cursor->isoWeekday()] ?? [];
    }

    /** true quando não há nenhuma janela definida em nenhum dia → tratar como 24x7. */
    private function is24x7(array $windows): bool
    {
        foreach ($windows as $wins) {
            if (!empty($wins)) return false;
        }
        return true;
    }
}
