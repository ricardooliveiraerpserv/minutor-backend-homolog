<?php

namespace App\Services;

use App\Models\OkrCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — fontes AUTOMÁTICAS de KRs. Framework que resolve o valor de um
 * indicador (data_source_key) para uma competência a partir de dados que já
 * existem no Minutor, e sincroniza em okr_monthly_results (source=automatico).
 *
 * Os resolvers são GUARDADOS por Schema::hasColumn: se o schema divergir, a
 * fonte degrada (ok=false) em vez de quebrar. Lançamentos manuais não são
 * tocados pela sincronização automática (só KRs com data_source_type=automatico).
 */
class OkrAutomationService
{
    /** Override de resolvers para testes. */
    private static ?array $override = null;

    public static function fake(?array $map): void
    {
        self::$override = $map;
    }

    /** Catálogo de fontes disponíveis (exibido no cadastro do KR). */
    public function catalog(): array
    {
        return [
            ['key' => 'consultoria.horas_apontadas', 'area' => 'Consultoria', 'label' => 'Horas apontadas', 'unit' => 'horas', 'aggregation' => 'acumulativo', 'description' => 'Soma das horas de timesheet no mês (status aprovado/pendente).'],
            ['key' => 'comercial.novos_contratos', 'area' => 'Comercial', 'label' => 'Novos contratos', 'unit' => 'quantidade', 'aggregation' => 'acumulativo', 'description' => 'Contratos assinados no mês.'],
            ['key' => 'comercial.receita_contratada', 'area' => 'Comercial', 'label' => 'Receita contratada', 'unit' => 'reais', 'aggregation' => 'acumulativo', 'description' => 'Soma do valor de projeto dos contratos assinados no mês.'],
            ['key' => 'cs.clientes_ativos', 'area' => 'Customer Success', 'label' => 'Clientes ativos', 'unit' => 'quantidade', 'aggregation' => 'posicao_fechamento', 'description' => 'Quantidade de clientes ativos (posição atual).'],
            ['key' => 'financeiro.receita_faturada', 'area' => 'Financeiro', 'label' => 'Receita faturada', 'unit' => 'reais', 'aggregation' => 'acumulativo', 'description' => 'Total faturado no fechamento de clientes da competência.'],
        ];
    }

    /** @return array<string,callable> key → fn(string $yearMonth): array{ok,value,error} */
    public function resolvers(): array
    {
        return self::$override ?? $this->defaultResolvers();
    }

    public function resolve(string $key, string $yearMonth): array
    {
        $r = $this->resolvers()[$key] ?? null;
        if (!$r) {
            return ['ok' => false, 'value' => null, 'error' => 'fonte_desconhecida'];
        }
        try {
            return $r($yearMonth);
        } catch (\Throwable $e) {
            return ['ok' => false, 'value' => null, 'error' => 'erro_resolver'];
        }
    }

    /**
     * Sincroniza os KRs automáticos de um ciclo. $isClosed(cycleId, areaId, ym)
     * decide se o mês está fechado (não sobrescreve). Retorna um relatório.
     */
    public function syncCycle(OkrCycle $cycle, ?string $onlyYm, int $actorId, callable $isClosed): array
    {
        $synced = [];
        $skipped = [];

        $cycle->loadMissing(['objectives.keyResults.monthlyTargets', 'objectives']);
        foreach ($cycle->objectives as $obj) {
            foreach ($obj->keyResults as $kr) {
                if ($kr->data_source_type !== 'automatico' || !$kr->data_source_key) {
                    continue;
                }
                // Meses a sincronizar: os que têm meta definida (ou o mês pedido).
                $months = $onlyYm
                    ? [$onlyYm]
                    : $kr->monthlyTargets->pluck('year_month')->all();

                foreach ($months as $ym) {
                    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $ym)) {
                        continue;
                    }
                    if ($isClosed($cycle->id, $obj->okr_area_id, $ym)) {
                        $skipped[] = ['key_result_id' => $kr->id, 'year_month' => $ym, 'reason' => 'mes_fechado'];
                        continue;
                    }
                    $res = $this->resolve($kr->data_source_key, $ym);
                    if (!$res['ok']) {
                        $skipped[] = ['key_result_id' => $kr->id, 'year_month' => $ym, 'reason' => $res['error'] ?? 'indisponivel'];
                        continue;
                    }
                    $row = \App\Models\OkrMonthlyResult::firstOrNew([
                        'okr_key_result_id' => $kr->id,
                        'year_month' => $ym,
                    ]);
                    $row->actual_value = $res['value'];
                    $row->source = 'automatico';
                    $row->reported_by = $actorId;
                    $row->reported_at = now();
                    $row->save();
                    $synced[] = ['key_result_id' => $kr->id, 'year_month' => $ym, 'value' => $res['value']];
                }
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped, 'synced_count' => count($synced), 'skipped_count' => count($skipped)];
    }

    // ── Resolvers padrão (guardados por schema) ──────────────────────────────
    private function defaultResolvers(): array
    {
        return [
            'consultoria.horas_apontadas' => function (string $ym) {
                if (!Schema::hasTable('timesheets') || !Schema::hasColumn('timesheets', 'effort_minutes') || !Schema::hasColumn('timesheets', 'date')) {
                    return $this->unavailable();
                }
                $q = DB::table('timesheets')->whereRaw("to_char(date, 'YYYY-MM') = ?", [$ym]);
                if (Schema::hasColumn('timesheets', 'status')) {
                    $q->whereIn('status', ['approved', 'pending']);
                }
                if (Schema::hasColumn('timesheets', 'deleted_at')) {
                    $q->whereNull('deleted_at');
                }
                return ['ok' => true, 'value' => round(((float) $q->sum('effort_minutes')) / 60, 2), 'error' => null];
            },
            'comercial.novos_contratos' => function (string $ym) {
                if (!Schema::hasTable('contracts') || !Schema::hasColumn('contracts', 'data_assinatura')) {
                    return $this->unavailable();
                }
                $q = DB::table('contracts')->whereRaw("to_char(data_assinatura, 'YYYY-MM') = ?", [$ym]);
                if (Schema::hasColumn('contracts', 'deleted_at')) {
                    $q->whereNull('deleted_at');
                }
                return ['ok' => true, 'value' => (float) $q->count(), 'error' => null];
            },
            'comercial.receita_contratada' => function (string $ym) {
                if (!Schema::hasTable('contracts') || !Schema::hasColumn('contracts', 'data_assinatura') || !Schema::hasColumn('contracts', 'valor_projeto')) {
                    return $this->unavailable();
                }
                $q = DB::table('contracts')->whereRaw("to_char(data_assinatura, 'YYYY-MM') = ?", [$ym]);
                if (Schema::hasColumn('contracts', 'deleted_at')) {
                    $q->whereNull('deleted_at');
                }
                return ['ok' => true, 'value' => round((float) $q->sum('valor_projeto'), 2), 'error' => null];
            },
            'cs.clientes_ativos' => function (string $ym) {
                if (!Schema::hasTable('customers') || !Schema::hasColumn('customers', 'active')) {
                    return $this->unavailable();
                }
                $q = DB::table('customers')->where('active', true);
                if (Schema::hasColumn('customers', 'deleted_at')) {
                    $q->whereNull('deleted_at');
                }
                return ['ok' => true, 'value' => (float) $q->count(), 'error' => null];
            },
            'financeiro.receita_faturada' => function (string $ym) {
                if (!Schema::hasTable('fechamento_clientes') || !Schema::hasColumn('fechamento_clientes', 'total_geral') || !Schema::hasColumn('fechamento_clientes', 'year_month')) {
                    return $this->unavailable();
                }
                $val = DB::table('fechamento_clientes')->where('year_month', $ym)->sum('total_geral');
                return ['ok' => true, 'value' => round((float) $val, 2), 'error' => null];
            },
        ];
    }

    private function unavailable(): array
    {
        return ['ok' => false, 'value' => null, 'error' => 'fonte_indisponivel'];
    }
}
