<?php

namespace App\Services;

use App\Models\OkrKeyResult;
use App\Models\OkrPeriodClosure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Check-in mensal de OKR: o realizado do mês M é informado no início de M+1. Um KR está PENDENTE
 * quando, no mês de referência (= mês anterior):
 *  - o ciclo é do ano do mês e está aprovado/em execução;
 *  - o KR é de fonte MANUAL (os automáticos são preenchidos pela sincronização) e não está
 *    cancelado/descontinuado (nem o objetivo cancelado);
 *  - o KR tem meta para o mês (mês sem meta = KR inativo) e ainda não tem realizado;
 *  - o mês não está fechado (global ou para a área do objetivo).
 * O dono do check-in é o responsável do KR (ou, sem ele, o responsável do objetivo).
 * Fonte ÚNICA para o card do Meu Dia (ApprovalController::homeActions) e o lembrete recorrente
 * (ActionReminderRule 'okr_checkin' → actions:remind-pending).
 */
class OkrCheckinReminderService
{
    public const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    public static function referenceMonth(?Carbon $now = null): string
    {
        return ($now ?? now())->copy()->subMonthNoOverflow()->format('Y-m');
    }

    public static function referenceLabel(?Carbon $now = null): string
    {
        $ym = self::referenceMonth($now);
        return self::MONTHS[(int) substr($ym, 5, 2) - 1] . '/' . substr($ym, 0, 4);
    }

    /** @return Collection<int, OkrKeyResult> KRs com check-in pendente (opcionalmente só os do usuário). */
    public static function pendingKeyResults(?Carbon $now = null, ?int $userId = null): Collection
    {
        $ym = self::referenceMonth($now);
        $year = (int) substr($ym, 0, 4);

        $krs = OkrKeyResult::query()
            ->where('data_source_type', 'manual')
            ->whereNotIn('status', ['cancelado', 'descontinuado'])
            ->whereHas('objective', fn ($o) => $o->where('status', '!=', 'cancelado')
                ->whereHas('cycle', fn ($c) => $c->where('year', $year)->whereIn('status', ['aprovado', 'em_execucao'])))
            ->whereHas('monthlyTargets', fn ($t) => $t->where('year_month', $ym)->whereNotNull('target_value'))
            ->whereDoesntHave('monthlyResults', fn ($r) => $r->where('year_month', $ym)->whereNotNull('actual_value'))
            ->with('objective:id,okr_cycle_id,okr_area_id,responsavel_id,title')
            ->get();

        if ($krs->isEmpty()) {
            return $krs;
        }

        $closed = OkrPeriodClosure::where('year_month', $ym)->where('status', 'fechado')
            ->get(['okr_cycle_id', 'okr_area_id']);

        return $krs
            ->reject(fn (OkrKeyResult $kr) => $closed->contains(fn ($c) =>
                (int) $c->okr_cycle_id === (int) $kr->objective?->okr_cycle_id
                && ($c->okr_area_id === null || (int) $c->okr_area_id === (int) $kr->objective?->okr_area_id)))
            ->filter(fn (OkrKeyResult $kr) => self::ownerId($kr) !== null && ($userId === null || self::ownerId($kr) === $userId))
            ->values();
    }

    public static function ownerId(OkrKeyResult $kr): ?int
    {
        $id = $kr->responsavel_id ?: $kr->objective?->responsavel_id;
        return $id ? (int) $id : null;
    }

    /** Donos com pelo menos um check-in pendente (destinatários do lembrete recorrente). */
    public static function affectedUserIds(?Carbon $now = null): array
    {
        return self::pendingKeyResults($now)->map(fn ($kr) => self::ownerId($kr))->unique()->values()->all();
    }
}
