<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ServiceType;
use App\Models\Timesheet;
use App\Models\MovideskTicket;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ClientPortalController extends Controller
{
    public function portal(Request $request): JsonResponse
    {
        $user       = $request->user();
        $customerId = $request->get('customer_id');
        $period     = $request->get('period', 'all'); // all | month | quarter | year

        // Admins podem passar customer_id livremente; coordenadores não têm customer_id;
        // clientes (futuramente) veriam apenas o próprio customer_id.
        if (!$customerId) {
            return response()->json(['message' => 'customer_id obrigatório'], 422);
        }

        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['message' => 'Cliente não encontrado'], 404);
        }

        // ── Projetos do cliente ─────────────────────────────────────────────
        $projects = Project::with(['contractType', 'serviceType', 'childProjects.contractType', 'childProjects.serviceType'])
            ->where('customer_id', $customerId)
            ->whereNull('parent_project_id')
            ->get();

        // Coleta todos os IDs (pai + filho) para query de timesheets
        $allProjectIds = $projects->flatMap(fn($p) => collect([$p->id])->merge(
            $p->childProjects->pluck('id')
        ))->unique()->values()->toArray();

        // Período para filtrar timesheets
        $tsQuery = Timesheet::whereIn('project_id', $allProjectIds)
            ->where('status', '!=', Timesheet::STATUS_REJECTED);

        $this->applyPeriod($tsQuery, $period);

        $loggedMinutesByProject = $tsQuery
            ->select('project_id', DB::raw('SUM(effort_minutes) as total_minutes'))
            ->groupBy('project_id')
            ->pluck('total_minutes', 'project_id');

        // ── Overview ────────────────────────────────────────────────────────
        $overview = $this->buildOverview($projects, $loggedMinutesByProject);

        // ── Contratos agrupados ─────────────────────────────────────────────
        $contracts = $this->buildContracts($projects, $loggedMinutesByProject);

        // ── Projetos simplificados ──────────────────────────────────────────
        $projectsList = $this->buildProjects($projects, $loggedMinutesByProject);

        // ── Sustentação ─────────────────────────────────────────────────────
        $support = $this->buildSupport($customerId, $period);

        // ── Alertas ─────────────────────────────────────────────────────────
        $alerts = $this->buildAlerts($projectsList, $overview);

        return response()->json([
            'customer' => [
                'id'   => $customer->id,
                'name' => $customer->name,
            ],
            'period'    => $period,
            'overview'  => $overview,
            'contracts' => $contracts,
            'projects'  => $projectsList,
            'support'   => $support,
            'alerts'    => $alerts,
        ]);
    }

    // ── Customers list (para o select do portal) ─────────────────────────────
    public function customers(Request $request): JsonResponse
    {
        $customers = Customer::orderBy('name')->get(['id', 'name']);
        return response()->json($customers);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function applyPeriod($query, string $period): void
    {
        match ($period) {
            'month'   => $query->whereMonth('date', Carbon::now()->month)->whereYear('date', Carbon::now()->year),
            'quarter' => $query->where('date', '>=', Carbon::now()->startOfQuarter()),
            'year'    => $query->whereYear('date', Carbon::now()->year),
            default   => null, // all — sem filtro
        };
    }

    private function buildOverview(iterable $projects, $loggedMinutes): array
    {
        $totalSold     = 0;
        $totalConsumed = 0;
        $totalValue    = 0;

        foreach ($projects as $p) {
            $sold = $p->sold_hours ?? 0;
            $consumed = ($loggedMinutes[$p->id] ?? 0) / 60;

            // Incluir filhos também
            foreach ($p->childProjects ?? [] as $child) {
                $sold     += $child->sold_hours ?? 0;
                $consumed += ($loggedMinutes[$child->id] ?? 0) / 60;
                $totalValue += ($child->sold_hours ?? 0) * ($child->hourly_rate ?? 0);
            }

            $totalSold     += $sold;
            $totalConsumed += $consumed;
            $totalValue    += $sold * ($p->hourly_rate ?? 0);
        }

        $balancePct = $totalSold > 0 ? round(($totalConsumed / $totalSold) * 100, 1) : 0;
        $balance    = round($totalSold - $totalConsumed, 1);

        $status = 'ok';
        if ($balancePct >= 90 || $balance < 0) $status = 'critical';
        elseif ($balancePct >= 70) $status = 'warning';

        return [
            'balance_hours'   => $balance,
            'consumption_pct' => $balancePct,
            'investment'      => round($totalValue, 2),
            'status'          => $status,
            'total_sold'      => round($totalSold, 1),
            'total_consumed'  => round($totalConsumed, 1),
        ];
    }

    private function buildContracts(iterable $projects, $loggedMinutes): array
    {
        $grouped = [];

        $allProjects = collect();
        foreach ($projects as $p) {
            $allProjects->push($p);
            foreach ($p->childProjects ?? [] as $child) {
                $allProjects->push($child);
            }
        }

        foreach ($allProjects as $p) {
            $typeName = $p->contract_type_display ?? ($p->contractType->name ?? 'Sem tipo');
            $sold     = (float)($p->sold_hours ?? 0);
            $consumed = ($loggedMinutes[$p->id] ?? 0) / 60;
            $balance  = $sold - $consumed;
            $pct      = $sold > 0 ? round(($consumed / $sold) * 100, 1) : 0;

            if (!isset($grouped[$typeName])) {
                $grouped[$typeName] = [
                    'contract_type' => $typeName,
                    'sold_hours'    => 0,
                    'consumed_hours'=> 0,
                    'balance_hours' => 0,
                    'consumption_pct' => 0,
                    'project_count' => 0,
                    'status'        => 'ok',
                ];
            }

            $grouped[$typeName]['sold_hours']     += $sold;
            $grouped[$typeName]['consumed_hours']  += round($consumed, 1);
            $grouped[$typeName]['balance_hours']   += round($balance, 1);
            $grouped[$typeName]['project_count']++;
        }

        foreach ($grouped as &$g) {
            $s = $g['sold_hours'];
            $c = $g['consumed_hours'];
            $g['consumption_pct'] = $s > 0 ? round(($c / $s) * 100, 1) : 0;
            $g['sold_hours']      = round($s, 1);
            $pct = $g['consumption_pct'];
            $g['status'] = $pct >= 90 ? 'critical' : ($pct >= 70 ? 'warning' : 'ok');
        }

        return array_values($grouped);
    }

    private function buildProjects(iterable $projects, $loggedMinutes): array
    {
        $normalize = fn($s) => mb_strtolower(
            preg_replace('/[\x{0300}-\x{036f}]/u', '',
                normalizer_normalize($s ?? '', \Normalizer::FORM_D) ?: ($s ?? '')
            )
        );

        $list = [];

        foreach ($projects as $p) {
            $sold     = (float)($p->sold_hours ?? 0);
            $consumed = ($loggedMinutes[$p->id] ?? 0) / 60;
            $balance  = round($sold - $consumed, 1);
            $pct      = $sold > 0 ? round(($consumed / $sold) * 100, 1) : 0;
            $isSust   = str_contains($normalize($p->contract_type_display ?? ''), 'sustenta');

            $status = 'ok';
            if ($pct >= 90 || $balance < 0) $status = 'critical';
            elseif ($pct >= 70) $status = 'warning';

            $children = [];
            foreach ($p->childProjects ?? [] as $child) {
                $cSold     = (float)($child->sold_hours ?? 0);
                $cConsumed = ($loggedMinutes[$child->id] ?? 0) / 60;
                $cBalance  = round($cSold - $cConsumed, 1);
                $cPct      = $cSold > 0 ? round(($cConsumed / $cSold) * 100, 1) : 0;
                $cStatus   = $cPct >= 90 || $cBalance < 0 ? 'critical' : ($cPct >= 70 ? 'warning' : 'ok');

                $children[] = [
                    'id'              => $child->id,
                    'name'            => $child->name,
                    'status_display'  => $child->status_display,
                    'sold_hours'      => $cSold,
                    'consumed_hours'  => round($cConsumed, 1),
                    'balance_hours'   => $cBalance,
                    'consumption_pct' => $cPct,
                    'health'          => $cStatus,
                    'contract_type'   => $child->contract_type_display,
                    'is_sustentacao'  => str_contains($normalize($child->contract_type_display ?? ''), 'sustenta'),
                ];
            }

            $list[] = [
                'id'              => $p->id,
                'name'            => $p->name,
                'code'            => $p->code,
                'status_display'  => $p->status_display,
                'sold_hours'      => $sold,
                'consumed_hours'  => round($consumed, 1),
                'balance_hours'   => $balance,
                'consumption_pct' => $pct,
                'health'          => $status,
                'contract_type'   => $p->contract_type_display,
                'is_sustentacao'  => $isSust,
                'children'        => $children,
            ];
        }

        // Ordena por risco (critical primeiro, depois warning, depois ok) e por saldo asc
        usort($list, function ($a, $b) {
            $order = ['critical' => 0, 'warning' => 1, 'ok' => 2];
            $diff  = ($order[$a['health']] ?? 2) - ($order[$b['health']] ?? 2);
            return $diff !== 0 ? $diff : ($a['balance_hours'] - $b['balance_hours']);
        });

        return $list;
    }

    private function buildSupport(int $customerId, string $period): array
    {
        // Busca projetos de Sustentação do cliente
        $sustServiceType = ServiceType::where('code', 'sustentacao')
            ->orWhere('name', 'Sustentação')->first();

        if (!$sustServiceType) {
            return $this->emptySupportData();
        }

        $sustProjectIds = Project::where('customer_id', $customerId)
            ->where('service_type_id', $sustServiceType->id)
            ->pluck('id')
            ->toArray();

        if (empty($sustProjectIds)) {
            return $this->emptySupportData();
        }

        $tsBase = Timesheet::whereIn('project_id', $sustProjectIds)
            ->where('status', '!=', Timesheet::STATUS_REJECTED);

        $this->applyPeriod($tsBase, $period);

        $timesheets = (clone $tsBase)->get(['project_id', 'ticket', 'effort_minutes', 'date']);

        // Chamados únicos (pelo número do ticket)
        $uniqueTickets = $timesheets->whereNotNull('ticket')->pluck('ticket')->unique();
        $totalChamados = $uniqueTickets->count();

        // Horas consumidas
        $totalMinutes  = $timesheets->sum('effort_minutes');
        $totalHours    = round($totalMinutes / 60, 1);

        // Tempo médio por chamado
        $avgHours = $totalChamados > 0 ? round($totalHours / $totalChamados, 1) : 0;

        // Chamados abertos vs resolvidos (via MovideskTicket)
        $ticketIds = $uniqueTickets->filter(fn($t) => !empty($t))->values()->toArray();
        $moviTickets = MovideskTicket::whereIn('ticket_id', $ticketIds)->get(['ticket_id', 'status']);

        $resolved = $moviTickets->filter(fn($t) => in_array(
            mb_strtolower($t->status ?? ''),
            ['resolvido', 'fechado', 'encerrado', 'resolved', 'closed']
        ))->count();

        $open = $moviTickets->filter(fn($t) => !in_array(
            mb_strtolower($t->status ?? ''),
            ['resolvido', 'fechado', 'encerrado', 'resolved', 'closed']
        ))->count();

        // Chamados por mês (últimos 6 meses)
        try {
            $monthlyData = Timesheet::whereIn('project_id', $sustProjectIds)
                ->where('status', '!=', Timesheet::STATUS_REJECTED)
                ->whereNotNull('ticket')
                ->where('date', '>=', Carbon::now()->subMonths(5)->startOfMonth())
                ->selectRaw("strftime('%Y-%m', date) as month, COUNT(DISTINCT ticket) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn($r) => ['month' => $r->month, 'count' => (int)$r->count])
                ->values();
        } catch (\Throwable $e) {
            try {
                $monthlyData = Timesheet::whereIn('project_id', $sustProjectIds)
                    ->where('status', '!=', Timesheet::STATUS_REJECTED)
                    ->whereNotNull('ticket')
                    ->where('date', '>=', Carbon::now()->subMonths(5)->startOfMonth())
                    ->selectRaw("DATE_FORMAT(date, '%Y-%m') as month, COUNT(DISTINCT ticket) as count")
                    ->groupBy('month')
                    ->orderBy('month')
                    ->get()
                    ->map(fn($r) => ['month' => $r->month, 'count' => (int)$r->count])
                    ->values();
            } catch (\Throwable $e2) {
                $monthlyData = collect();
            }
        }

        return [
            'open_tickets'      => $open,
            'resolved_tickets'  => $resolved,
            'total_tickets'     => $totalChamados,
            'consumed_hours'    => $totalHours,
            'avg_hours_ticket'  => $avgHours,
            'monthly_tickets'   => $monthlyData,
        ];
    }

    private function emptySupportData(): array
    {
        return [
            'open_tickets'     => 0,
            'resolved_tickets' => 0,
            'total_tickets'    => 0,
            'consumed_hours'   => 0,
            'avg_hours_ticket' => 0,
            'monthly_tickets'  => [],
        ];
    }

    private function buildAlerts(array $projects, array $overview): array
    {
        $alerts = [];

        foreach ($projects as $p) {
            if ($p['consumption_pct'] >= 90) {
                $alerts[] = [
                    'type'       => 'critical',
                    'icon'       => 'alert',
                    'title'      => $p['name'],
                    'message'    => "{$p['consumption_pct']}% do contrato consumido",
                    'project_id' => $p['id'],
                ];
            } elseif ($p['balance_hours'] > 0 && $p['balance_hours'] < 10 && $p['sold_hours'] > 0) {
                $alerts[] = [
                    'type'       => 'warning',
                    'icon'       => 'low-balance',
                    'title'      => $p['name'],
                    'message'    => "Saldo abaixo de 10h ({$p['balance_hours']}h restantes)",
                    'project_id' => $p['id'],
                ];
            } elseif ($p['balance_hours'] < 0) {
                $alerts[] = [
                    'type'       => 'critical',
                    'icon'       => 'negative',
                    'title'      => $p['name'],
                    'message'    => "Saldo negativo (" . abs($p['balance_hours']) . "h)",
                    'project_id' => $p['id'],
                ];
            } elseif ($p['consumption_pct'] >= 70) {
                $alerts[] = [
                    'type'       => 'warning',
                    'icon'       => 'warning',
                    'title'      => $p['name'],
                    'message'    => "{$p['consumption_pct']}% consumido — atenção",
                    'project_id' => $p['id'],
                ];
            }
        }

        // Ordenar: critical primeiro
        usort($alerts, fn($a, $b) => ($a['type'] === 'critical' ? 0 : 1) - ($b['type'] === 'critical' ? 0 : 1));

        return $alerts;
    }
}
