<?php

namespace App\Services;

use App\Models\Partner;
use App\Models\User;
use App\Models\UserHourlyRateLog;

/**
 * Custo/hora EFETIVO do consultor numa competência — MESMA regra do relatório de Rentabilidade
 * (RelatorioRentabilidadeController::costMeta), extraída para reuso (EVM em R$) SEM tocar naquele controller:
 *   - consultor de parceiro FIXO → valor/hora do parceiro na competência;
 *   - senão, hourly_rate vigente (UserHourlyRateLog::effectiveValuesAt); se rate_type = monthly → salário ÷ 160.
 */
class ConsultorCostService
{
    /** cache [userId|YYYY-MM => custo/hora] */
    private array $cache = [];

    public function hourlyCost(?User $user, string $yearMonth): float
    {
        if (! $user) return 0.0;
        $key = $user->id.'|'.$yearMonth;
        if (isset($this->cache[$key])) return $this->cache[$key];

        if ($user->partner_id && $user->partner && $user->partner->pricing_type === Partner::PRICING_FIXED) {
            return $this->cache[$key] = (float) $user->partner->hourlyRateForCompetencia($yearMonth);
        }

        $hist = UserHourlyRateLog::effectiveValuesAt($user->id, $user, $yearMonth.'-01');
        $rate = (float) ($hist['hourly_rate'] ?? $user->hourly_rate ?? 0);
        $type = $hist['rate_type'] ?? $user->rate_type ?? 'hourly';
        $eff  = ($type === 'monthly' && $rate > 0) ? round($rate / 160, 4) : $rate;

        return $this->cache[$key] = $eff;
    }
}
