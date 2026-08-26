<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Models\MovideskTicket;

/**
 * SustentacaoMetrics — REGRA CANÔNICA ÚNICA do Portal de Sustentação.
 *
 * Fonte de verdade para SLA de solução, Tempo de Resolução, grupos de status
 * (aberto/parado/aguardando cliente), Aging e horas. Criado na Fase 1 para o
 * novo "Status de Suporte"; a migração das telas legadas (/kpis, /clients,
 * /sla, /context-stats, /evolution) para cá é a Fase 1.5 (rollout controlado).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CONTRATO DOS CONJUNTOS DE ESTADO (snapshot — sem filtro de período)
 * ─────────────────────────────────────────────────────────────────────────────
 * Universo "não encerrado" = tudo que NÃO é Resolved/Closed/Canceled.
 * Ele se divide em DOIS conjuntos DISJUNTOS:
 *
 *   open_operational  = new_in_attendance + stopped_internal
 *   waiting_client    = Stopped / status = "Pendente cliente"
 *
 * onde:
 *   new_in_attendance = base_status ∈ (New, InAttendance)
 *   stopped_internal  = base_status = Stopped ∧ TRIM(status) ∈ STOPPED_INTERNAL
 *                       (SUBCONJUNTO de open_operational — NÃO somar de novo)
 *
 * ⚠️ open_operational, stopped_internal e new_in_attendance NÃO são universos
 *    independentes somáveis: stopped_internal ⊂ open_operational.
 *    Somável apenas: open_operational + waiting_client = total não-encerrado.
 *
 * TRIM(status) é aplicado SEMPRE (o dado tem espaço à direita, ex.:
 * "Pendente cliente " len 17). Não há UPDATE no banco — normalização só na
 * camada de leitura.
 */
class SustentacaoMetrics
{
    /** Sub-status de "Stopped" que representam parada dependente da operação interna/terceiros/TOTVS/agendamento/aprovação. */
    public const STOPPED_INTERNAL = [
        'Pendente Terceiros',
        'Pendente TOTVS',
        'Agendado',
        'Pendente aprovação',
        'Pausado',
    ];

    /** Sub-status de "Stopped" que representa espera por retorno do cliente (fora da operação ativa). */
    public const WAITING_CLIENT = [
        'Pendente cliente',
    ];

    /** Ordem canônica de prioridade (o dado real usa "Média", não "Normal"). */
    public const URGENCY_ORDER = ['Urgente', 'Alta', 'Média', 'Baixa'];

    public function __construct(private readonly ?int $companyId = null) {}

    /** Query base de tickets: exclui responsáveis @promax.bardahl.com.br (regra herdada do controller). */
    private function tickets(): Builder
    {
        return MovideskTicket::where(function ($q) {
            $q->whereNull('owner_email')
              ->orWhere('owner_email', 'not ilike', '%@promax.bardahl.com.br');
        });
    }

    /**
     * Filtro "aberto (operação)": Novo/Em Atendimento OU Parado interno.
     * NÃO inclui "Pendente cliente". Usa TRIM(status).
     */
    public function applyOpenOperational(Builder $q): Builder
    {
        return $q->where(function ($inner) {
            $inner->whereIn('base_status', ['New', 'InAttendance'])
                  ->orWhere(fn($s) => $s->where('base_status', 'Stopped')
                                        ->whereIn(DB::raw('TRIM(status)'), self::STOPPED_INTERNAL));
        });
    }

    // ── SLA de Solução (REGRA CANÔNICA — âncora resolved_in) ──────────────────
    // Denominador: resolved_in ∈ [from,to] ∧ sla_solution_date IS NOT NULL
    // Numerador  : denominador ∧ resolved_in <= sla_solution_date
    public function slaSolution(Carbon $from, Carbon $to): array
    {
        $den = $this->tickets()
            ->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_date')
            ->count();

        $num = $this->tickets()
            ->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_date')
            ->whereColumn('resolved_in', '<=', 'sla_solution_date')
            ->count();

        return [
            'rate' => $den > 0 ? round($num / $den * 100, 1) : null,
            'num'  => $num,
            'den'  => $den,
        ];
    }

    /** Tempo de Resolução executivo = MEDIANA de sla_solution_time (min) → horas. Resolvidos no período. */
    public function resolutionMedianHours(Carbon $from, Carbon $to): ?float
    {
        $medMin = $this->tickets()
            ->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_time')
            ->select(DB::raw('percentile_cont(0.5) WITHIN GROUP (ORDER BY sla_solution_time) AS med'))
            ->value('med');

        return $medMin !== null ? round(((float) $medMin) / 60, 1) : null;
    }

    public function createdCount(Carbon $from, Carbon $to): int
    {
        return $this->tickets()->whereBetween('created_date', [$from, $to])->count();
    }

    public function resolvedCount(Carbon $from, Carbon $to): int
    {
        return $this->tickets()->whereBetween('resolved_in', [$from, $to])->count();
    }

    /** Grupos de estado (snapshot). Ver contrato no docblock da classe. */
    public function openGroups(): array
    {
        $newInAtt = (clone $this->tickets())->whereIn('base_status', ['New', 'InAttendance'])->count();

        $stoppedInternal = (clone $this->tickets())
            ->where('base_status', 'Stopped')
            ->whereIn(DB::raw('TRIM(status)'), self::STOPPED_INTERNAL)
            ->count();

        $waitingClient = (clone $this->tickets())
            ->where('base_status', 'Stopped')
            ->whereIn(DB::raw('TRIM(status)'), self::WAITING_CLIENT)
            ->count();

        return [
            'new_in_attendance' => $newInAtt,
            'stopped_internal'  => $stoppedInternal,          // ⊂ open_operational
            'open_operational'  => $newInAtt + $stoppedInternal,
            'waiting_client'    => $waitingClient,            // disjunto
        ];
    }

    /** Aging (dias desde created_date) sobre o backlog OPERACIONAL (não inclui Pendente cliente). */
    public function agingBuckets(): array
    {
        $row = $this->applyOpenOperational($this->tickets())
            ->whereNotNull('created_date')
            ->selectRaw("
                SUM(CASE WHEN NOW() - created_date <  INTERVAL '4 days'  THEN 1 ELSE 0 END) as d0_3,
                SUM(CASE WHEN NOW() - created_date >= INTERVAL '4 days'  AND NOW() - created_date < INTERVAL '8 days'  THEN 1 ELSE 0 END) as d4_7,
                SUM(CASE WHEN NOW() - created_date >= INTERVAL '8 days'  AND NOW() - created_date < INTERVAL '16 days' THEN 1 ELSE 0 END) as d8_15,
                SUM(CASE WHEN NOW() - created_date >= INTERVAL '16 days' THEN 1 ELSE 0 END) as d15_plus
            ")->first();

        return [
            'd0_3'     => (int) ($row->d0_3     ?? 0),
            'd4_7'     => (int) ($row->d4_7     ?? 0),
            'd8_15'    => (int) ($row->d8_15    ?? 0),
            'd15_plus' => (int) ($row->d15_plus ?? 0),
        ];
    }

    /** SLA vencido agora: backlog operacional com sla_solution_date no passado e ainda não resolvido/fechado. */
    public function slaBreachedNow(): int
    {
        return $this->applyOpenOperational($this->tickets())
            ->whereNull('resolved_in')
            ->whereNull('closed_in')
            ->whereNotNull('sla_solution_date')
            ->where('sla_solution_date', '<', now())
            ->count();
    }

    /** Distribuição por Tipo de Atendimento (categoria). Criados no período. */
    public function byCategoria(Carbon $from, Carbon $to): array
    {
        return $this->tickets()
            ->whereBetween('created_date', [$from, $to])
            ->whereNotNull('categoria')
            ->selectRaw('categoria as label, COUNT(*) as count')
            ->groupBy('categoria')
            ->orderByDesc('count')
            ->get()
            ->map(fn($r) => ['label' => $r->label, 'count' => (int) $r->count])
            ->all();
    }

    /**
     * Distribuição por Módulo (servico) — Top N + "Outros" agregado.
     * servico tem alta cardinalidade (65 valores) → nunca usar pizza.
     */
    public function byServicoTop(Carbon $from, Carbon $to, int $top = 10): array
    {
        $rows = $this->tickets()
            ->whereBetween('created_date', [$from, $to])
            ->whereNotNull('servico')
            ->selectRaw('servico as label, COUNT(*) as count')
            ->groupBy('servico')
            ->orderByDesc('count')
            ->get()
            ->map(fn($r) => ['label' => $r->label, 'count' => (int) $r->count])
            ->all();

        $head   = array_slice($rows, 0, $top);
        $tail   = array_slice($rows, $top);
        $others = array_sum(array_column($tail, 'count'));

        return [
            'top'          => $head,
            'others'       => $others,
            'others_count' => count($tail),
            'total'        => array_sum(array_column($rows, 'count')),
        ];
    }

    /**
     * SLA de Solução agrupado por urgência (regra canônica resolved-anchor),
     * TODOS os buckets (inclui '(sem)'). A soma de num/den por urgência fecha
     * EXATAMENTE com o SLA de Solução global. Keyed pelo rótulo de urgência.
     */
    public function slaSolutionByUrgency(Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        return $this->tickets()
            ->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_date')
            ->selectRaw('COALESCE(TRIM(urgencia), \'(sem)\') as urgencia')
            ->selectRaw('COUNT(*) as den')
            ->selectRaw('SUM(CASE WHEN resolved_in <= sla_solution_date THEN 1 ELSE 0 END) as num')
            ->groupByRaw('COALESCE(TRIM(urgencia), \'(sem)\')')
            ->get()
            ->keyBy('urgencia');
    }

    /** SLA por prioridade (deriva de slaSolutionByUrgency), na ordem de urgência. */
    public function slaByPriority(Carbon $from, Carbon $to): array
    {
        $rows = $this->slaSolutionByUrgency($from, $to);
        $out = [];
        foreach (self::URGENCY_ORDER as $u) {
            $r = $rows->get($u);
            if (!$r) continue;
            $out[] = [
                'urgencia' => $u,
                'den'      => (int) $r->den,
                'num'      => (int) $r->num,
                'rate'     => $r->den > 0 ? round($r->num / $r->den * 100, 1) : null,
            ];
        }
        return $out;
    }

    /**
     * SLA de Solução por cliente (regra canônica resolved-anchor): keyed por
     * customer_id → {num, den}. Denominador = população SLA aplicável DAQUELE
     * cliente (resolvidos no período com sla_solution_date), NÃO o total de
     * tickets do período.
     */
    public function slaSolutionByClient(Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        return $this->tickets()
            ->whereBetween('resolved_in', [$from, $to])
            ->whereNotNull('sla_solution_date')
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id')
            ->selectRaw('COUNT(*) as den')
            ->selectRaw('SUM(CASE WHEN resolved_in <= sla_solution_date THEN 1 ELSE 0 END) as num')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');
    }

    // ── Horas (timesheets de sustentação) ────────────────────────────────────
    private function sustentacaoTimesheets(Carbon $from, Carbon $to)
    {
        return DB::table('timesheets')
            ->join('projects', 'projects.id', '=', 'timesheets.project_id')
            ->when($this->companyId, fn ($q, $cid) => $q->where('projects.company_id', $cid))
            ->join('service_types', 'service_types.id', '=', 'projects.service_type_id')
            ->where(function ($q) {
                $q->where('service_types.code', 'sustentacao')
                  ->orWhere('service_types.name', 'ilike', '%sustenta%')
                  ->orWhere(function ($s) {
                      $s->where('projects.is_investimento_comercial', true)
                        ->where('projects.categoria_interna', 'Suporte');
                  });
            })
            ->whereBetween('timesheets.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('timesheets.status', ['approved', 'pending']);
    }

    /** Total de horas de suporte no período (KPI oficial de horas). */
    public function hours(Carbon $from, Carbon $to): float
    {
        $min = (clone $this->sustentacaoTimesheets($from, $to))->sum('timesheets.effort_minutes');
        return round(((int) $min) / 60, 1);
    }

    /**
     * Esforço médio por ticket resolvido (h) = horas de sustentação (TODOS os
     * clientes) ÷ tickets resolvidos no período. Correção do bug que limitava o
     * numerador aos Top-12 clientes. NÃO é "horas gastas neste ticket" — é
     * densidade de esforço da operação por ticket resolvido.
     */
    public function effortPerResolvedHours(Carbon $from, Carbon $to): ?float
    {
        $hours    = $this->hours($from, $to);
        $resolved = $this->resolvedCount($from, $to);
        return ($resolved > 0 && $hours > 0) ? round($hours / $resolved, 2) : null;
    }
}
