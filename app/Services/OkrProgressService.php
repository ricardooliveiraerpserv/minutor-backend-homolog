<?php

namespace App\Services;

/**
 * Cálculo de progresso de KRs. Regras documentadas no PRD (seção 6).
 *
 * Separa ATINGIMENTO (progresso numérico, ignora tempo) de ADERÊNCIA (realizado
 * acumulado vs. planejado acumulado até o mês de referência). O semáforo usa a
 * ADERÊNCIA — um KR não é "atrasado" só porque a meta anual ainda não foi atingida.
 *
 * Nunca usa uma fórmula única: cada direção (aumentar/reduzir/manter_faixa) tem
 * a sua. Valores de realizado NULL significam "não informado" (pendente) e não
 * entram como zero. Todos os retornos percentuais são round(2).
 */
class OkrProgressService
{
    public const STATUS_VERDE = 'verde';
    public const STATUS_AMARELO = 'amarelo';
    public const STATUS_VERMELHO = 'vermelho';
    public const STATUS_CINZA = 'cinza';

    /**
     * Atingimento (%) de um KR dado sua direção. Retorna null quando não há como
     * calcular (realizado ausente, ou faixa sem limites). >100 = meta superada.
     */
    public function achievement(
        string $direction,
        ?float $baseline,
        ?float $target,
        ?float $actual,
        ?float $bandMin = null,
        ?float $bandMax = null
    ): ?float {
        if ($actual === null) {
            return null; // pendente ≠ zero
        }

        return match ($direction) {
            'aumentar'     => $this->achievementIncrease((float) $baseline, (float) $target, $actual),
            'reduzir'      => $this->achievementDecrease((float) $baseline, (float) $target, $actual),
            'manter_faixa' => $this->withinBand($actual, $bandMin, $bandMax) ? 100.0 : 0.0,
            default        => null,
        };
    }

    /** AUMENTAR: quanto maior, melhor. (atual - baseline) / (target - baseline). */
    public function achievementIncrease(float $baseline, float $target, float $actual): float
    {
        $denom = $target - $baseline;
        if ($denom == 0.0) {
            // Meta degenerada (baseline == target): atingida se alcançou o alvo.
            return $actual >= $target ? 100.0 : 0.0;
        }
        $pct = (($actual - $baseline) / $denom) * 100;
        return $this->floorZero(round($pct, 2));
    }

    /** REDUZIR: quanto menor, melhor. (baseline - atual) / (baseline - target). */
    public function achievementDecrease(float $baseline, float $target, float $actual): float
    {
        $denom = $baseline - $target;
        if ($denom == 0.0) {
            return $actual <= $target ? 100.0 : 0.0;
        }
        $pct = (($baseline - $actual) / $denom) * 100;
        return $this->floorZero(round($pct, 2));
    }

    /** MANTER EM FAIXA: dentro de [band_min, band_max]. */
    public function withinBand(?float $actual, ?float $bandMin, ?float $bandMax): bool
    {
        if ($actual === null || $bandMin === null || $bandMax === null) {
            return false;
        }
        return $actual >= $bandMin && $actual <= $bandMax;
    }

    /**
     * Aderência (%) ao planejado até o mês de referência: realizado acumulado ÷
     * planejado acumulado. Retorna null quando não há base (planejado 0/null ou
     * sem realizado informado) — vira semáforo CINZA.
     */
    public function adherence(?float $realizadoAcumulado, ?float $planejadoAcumulado): ?float
    {
        if ($realizadoAcumulado === null || $planejadoAcumulado === null || $planejadoAcumulado == 0.0) {
            return null;
        }
        return round(($realizadoAcumulado / $planejadoAcumulado) * 100, 2);
    }

    /**
     * Semáforo a partir da aderência (%). Limiares configuráveis por ciclo
     * (okr_cycles.settings → thresholds()); default verde ≥95, amarelo ≥80.
     */
    public function status(?float $adherencePct, array $thresholds = ['verde' => 95, 'amarelo' => 80]): string
    {
        if ($adherencePct === null) {
            return self::STATUS_CINZA;
        }
        if ($adherencePct >= $thresholds['verde']) {
            return self::STATUS_VERDE;
        }
        if ($adherencePct >= $thresholds['amarelo']) {
            return self::STATUS_AMARELO;
        }
        return self::STATUS_VERMELHO;
    }

    /**
     * Distribuição automática de uma meta anual acumulativa em 12 meses, com o
     * resto somado aos últimos meses (soma mensal == meta anual).
     *
     * @return array<int,float> índice 0..11 = jan..dez
     */
    public function autoDistribute(float $annualTarget, int $months = 12): array
    {
        $months = max(1, $months);
        $base = floor(($annualTarget / $months) * 100) / 100; // 2 casas, arredonda p/ baixo
        $dist = array_fill(0, $months, $base);
        $remainder = round($annualTarget - ($base * $months), 2);

        // Espalha o resto (em centavos) a partir do último mês.
        $cents = (int) round($remainder * 100);
        for ($i = 0; $i < $cents; $i++) {
            $idx = $months - 1 - ($i % $months);
            $dist[$idx] = round($dist[$idx] + 0.01, 2);
        }
        return $dist;
    }

    /**
     * Progresso (%) rumo à meta para um valor absoluto `v`, conforme a direção.
     * manter_faixa: 100 se dentro da faixa, senão 0. Null se v null.
     */
    public function progressOf(string $direction, ?float $baseline, ?float $target, ?float $v, ?float $bandMin = null, ?float $bandMax = null): ?float
    {
        if ($v === null) {
            return null;
        }
        if ($direction === 'manter_faixa') {
            return $this->withinBand($v, $bandMin, $bandMax) ? 100.0 : 0.0;
        }
        return $this->achievement($direction, $baseline, $target, $v, $bandMin, $bandMax);
    }

    /**
     * Série dos 12 meses de um KR com acumulados, atingimento anual, aderência e
     * semáforo — respeitando a agregação (acumulativo soma; média não soma;
     * posição de fechamento usa o último valor). Função PURA (sem DB).
     *
     * @param array<int,float|null> $targets competência 1..12 → meta (null = sem meta)
     * @param array<int,float|null> $actuals competência 1..12 → realizado (null = pendente)
     */
    public function monthlySeries(
        string $direction,
        string $aggregation,
        ?float $baseline,
        ?float $target,
        ?float $bandMin,
        ?float $bandMax,
        array $targets,
        array $actuals,
        array $thresholds = ['verde' => 95, 'amarelo' => 80]
    ): array {
        // Mês de referência = último mês com realizado informado.
        $refMonth = null;
        for ($m = 12; $m >= 1; $m--) {
            if (($actuals[$m] ?? null) !== null) { $refMonth = $m; break; }
        }

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $t = $actuals[$m] ?? null;
            $plannedAcc = $this->aggregate('planned', $aggregation, $targets, $m);
            $actualAcc = $this->aggregate('actual', $aggregation, $actuals, $m);
            $monthStatus = self::STATUS_CINZA;
            if (($actuals[$m] ?? null) !== null) {
                $ad = $this->paceAdherence($direction, $baseline, $target, $bandMin, $bandMax, $actuals, $targets, $aggregation, $m);
                $monthStatus = $this->status($ad, $thresholds);
            }
            $months[] = [
                'month' => $m,
                'target' => $this->round($targets[$m] ?? null),
                'actual' => $this->round($t),
                'accumulated_target' => $this->round($plannedAcc),
                'accumulated_actual' => $this->round($actualAcc),
                'status' => $monthStatus,
            ];
        }

        // Atingimento anual.
        if ($direction === 'manter_faixa') {
            $achievement = $this->bandAchievement($actuals, $bandMin, $bandMax, 12);
            $actualAnnual = null; // não se aplica um valor único
        } else {
            $actualAnnual = $this->aggregate('actual', $aggregation, $actuals, 12);
            $achievement = $this->progressOf($direction, $baseline, $target, $actualAnnual, $bandMin, $bandMax);
        }

        $adherence = $refMonth
            ? $this->paceAdherence($direction, $baseline, $target, $bandMin, $bandMax, $actuals, $targets, $aggregation, $refMonth)
            : null;

        return [
            'months' => $months,
            'summary' => [
                'reference_month' => $refMonth,
                'actual' => $this->round($actualAnnual),
                'accumulated_actual' => $refMonth ? $this->round($this->aggregate('actual', $aggregation, $actuals, $refMonth)) : null,
                'accumulated_planned' => $refMonth ? $this->round($this->aggregate('planned', $aggregation, $targets, $refMonth)) : null,
                'achievement' => $achievement === null ? null : round($achievement, 2),
                'adherence' => $adherence,
                'status' => $this->status($adherence, $thresholds),
            ],
        ];
    }

    /** Aderência (ritmo) no mês M: progresso do realizado ÷ progresso do planejado. */
    private function paceAdherence(string $direction, ?float $baseline, ?float $target, ?float $bandMin, ?float $bandMax, array $actuals, array $targets, string $aggregation, int $m): ?float
    {
        if ($direction === 'manter_faixa') {
            $informed = 0; $inBand = 0;
            for ($i = 1; $i <= $m; $i++) {
                if (($actuals[$i] ?? null) !== null) {
                    $informed++;
                    if ($this->withinBand($actuals[$i], $bandMin, $bandMax)) { $inBand++; }
                }
            }
            return $informed === 0 ? null : round(($inBand / $informed) * 100, 2);
        }

        $actualRef = $this->aggregate('actual', $aggregation, $actuals, $m);
        $plannedRef = $this->aggregate('planned', $aggregation, $targets, $m);
        $expected = $this->progressOf($direction, $baseline, $target, $plannedRef, $bandMin, $bandMax);
        $actualProg = $this->progressOf($direction, $baseline, $target, $actualRef, $bandMin, $bandMax);
        if ($expected === null || $expected <= 0 || $actualProg === null) {
            return null;
        }
        return round(($actualProg / $expected) * 100, 2);
    }

    /** % de meses dentro da faixa até o mês M (sobre os meses informados). */
    private function bandAchievement(array $actuals, ?float $bandMin, ?float $bandMax, int $m): ?float
    {
        $informed = 0; $inBand = 0;
        for ($i = 1; $i <= $m; $i++) {
            if (($actuals[$i] ?? null) !== null) {
                $informed++;
                if ($this->withinBand($actuals[$i], $bandMin, $bandMax)) { $inBand++; }
            }
        }
        return $informed === 0 ? null : round(($inBand / $informed) * 100, 2);
    }

    /**
     * Agrega valores (planned=targets, actual=realizados) até o mês M conforme a
     * agregação. acumulativo=soma; media=média dos informados; posicao=último informado.
     */
    private function aggregate(string $kind, string $aggregation, array $values, int $m): ?float
    {
        $slice = [];
        for ($i = 1; $i <= $m; $i++) {
            if (($values[$i] ?? null) !== null) { $slice[$i] = (float) $values[$i]; }
        }
        if ($aggregation === 'posicao_fechamento') {
            // Último informado (planned usa o alvo do próprio mês M).
            if ($kind === 'planned') {
                return ($values[$m] ?? null) !== null ? (float) $values[$m] : null;
            }
            if (empty($slice)) { return null; }
            return end($slice);
        }
        if ($aggregation === 'media') {
            return empty($slice) ? null : array_sum($slice) / count($slice);
        }
        // acumulativo
        return empty($slice) ? ($kind === 'actual' ? null : 0.0) : array_sum($slice);
    }

    /**
     * Monta a série mensal de um KR a partir das suas metas e realizações
     * (relações monthlyTargets/monthlyResults). Usa os limiares do ciclo.
     */
    public function seriesForKeyResult(\App\Models\OkrKeyResult $kr): array
    {
        $targets = array_fill(1, 12, null);
        $actuals = array_fill(1, 12, null);

        foreach ($kr->monthlyTargets as $t) {
            $m = (int) substr($t->year_month, 5, 2);
            if ($m >= 1 && $m <= 12) {
                $targets[$m] = $t->target_value === null ? null : (float) $t->target_value;
            }
        }
        foreach ($kr->monthlyResults as $r) {
            $m = (int) substr($r->year_month, 5, 2);
            if ($m >= 1 && $m <= 12) {
                $actuals[$m] = $r->actual_value === null ? null : (float) $r->actual_value;
            }
        }

        $cycle = $kr->objective?->cycle;
        $thresholds = $cycle ? $cycle->thresholds() : ['verde' => 95, 'amarelo' => 80];
        $f = fn ($v) => $v === null ? null : (float) $v;

        return $this->monthlySeries(
            $kr->direction,
            $kr->aggregation,
            $f($kr->baseline_value),
            $f($kr->target_value),
            $f($kr->band_min),
            $f($kr->band_max),
            $targets,
            $actuals,
            $thresholds,
        );
    }

    private function round(?float $v): ?float
    {
        return $v === null ? null : round($v, 2);
    }

    private function floorZero(float $v): float
    {
        return $v < 0 ? 0.0 : $v;
    }
}
