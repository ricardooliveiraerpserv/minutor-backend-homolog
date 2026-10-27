<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Scopes\HideAusterFrozenScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AusterIndicatorsController extends Controller
{
    private const AUSTER_CUSTOMER_ID = HideAusterFrozenScope::AUSTER_CUSTOMER_ID;

    private function denyIfNotAllowed(): ?JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Não autenticado'], 401);
        }
        if ($user->isAdmin()) return null;
        // Cliente da Auster vê os próprios indicadores
        if ($user->type === 'cliente' && (int) $user->customer_id === self::AUSTER_CUSTOMER_ID) {
            return null;
        }
        return response()->json(['message' => 'Acesso negado'], 403);
    }

    /**
     * Lista todos os subprojetos Fechado da Auster (históricos + recentes).
     * Antes restringia a start_date < 2025-05-01; agora traz tudo pra o cliente
     * ver projetos antigos e atuais juntos. Recentemente desvinculados
     * (parent virou NULL) continuam fora.
     */
    public function projects(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;

        $rows = Project::withoutGlobalScope(HideAusterFrozenScope::class)
            ->with(['contractType'])
            ->where('customer_id', self::AUSTER_CUSTOMER_ID)
            ->whereNotNull('parent_project_id')
            ->whereNotNull('start_date')
            ->whereHas('contractType', function ($q) {
                $q->where('code', 'closed')->orWhereRaw('LOWER(TRIM(name)) = ?', ['fechado']);
            })
            ->orderBy('start_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $ids = $rows->pluck('id')->all();

        $loggedMap = DB::table('timesheets')
            ->whereIn('project_id', $ids)
            ->whereIn('status', ['approved', 'pending'])
            ->selectRaw('project_id, COALESCE(SUM(effort_minutes), 0) AS minutes')
            ->groupBy('project_id')
            ->pluck('minutes', 'project_id');

        $items = $rows->map(function (Project $p) use ($loggedMap) {
            $loggedHours   = round(((int) ($loggedMap[$p->id] ?? 0)) / 60, 2);
            $initial       = (float) ($p->initial_hours_consumed ?? 0);
            $consumed      = round($loggedHours + $initial, 2);
            $sold          = (float) ($p->sold_hours ?? 0);
            $balance       = round($sold - $consumed, 2);

            return [
                'id'              => $p->id,
                'code'            => $p->code,
                'name'            => $p->name,
                'start_date'      => optional($p->start_date)->format('Y-m-d'),
                'status'          => $p->status,
                'status_display'  => $p->status_display,
                'contract_type'   => optional($p->contractType)->name,
                'parent_project_id' => $p->parent_project_id,
                'sold_hours'      => $sold,
                'consumed_hours'  => $consumed,
                'balance_hours'   => $balance,
            ];
        });

        $totalSold     = round($items->sum('sold_hours'), 2);
        $totalConsumed = round($items->sum('consumed_hours'), 2);
        $totalProjects = $items->count();
        $finished      = $items->where('status', Project::STATUS_FINISHED)->count();
        $finishedPct   = $totalProjects > 0
            ? round(($finished / $totalProjects) * 100, 1)
            : 0.0;

        return response()->json([
            'items'   => $items,
            'summary' => [
                'total_projects'        => $totalProjects,
                'total_sold_hours'      => $totalSold,
                'total_consumed_hours'  => $totalConsumed,
                'finished_projects'     => $finished,
                'finished_percentage'   => $finishedPct,
            ],
        ]);
    }

    /**
     * Top N projetos da Auster por horas consumidas.
     * Aceita ?limit=5|10|20|all (default: 10).
     */
    public function topConsumed(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;

        $limitParam = (string) $request->get('limit', '10');

        $rows = Project::withoutGlobalScope(HideAusterFrozenScope::class)
            ->with(['contractType'])
            ->where('customer_id', self::AUSTER_CUSTOMER_ID)
            ->whereNotNull('parent_project_id')
            ->whereNotNull('start_date')
            ->whereHas('contractType', function ($q) {
                $q->where('code', 'closed')->orWhereRaw('LOWER(TRIM(name)) = ?', ['fechado']);
            })
            ->get();

        $ids = $rows->pluck('id')->all();

        $loggedMap = DB::table('timesheets')
            ->whereIn('project_id', $ids)
            ->whereIn('status', ['approved', 'pending'])
            ->selectRaw('project_id, COALESCE(SUM(effort_minutes), 0) AS minutes')
            ->groupBy('project_id')
            ->pluck('minutes', 'project_id');

        $items = $rows
            ->map(function (Project $p) use ($loggedMap) {
                $loggedHours   = round(((int) ($loggedMap[$p->id] ?? 0)) / 60, 2);
                $initial       = (float) ($p->initial_hours_consumed ?? 0);
                $consumed      = round($loggedHours + $initial, 2);

                return [
                    'id'             => $p->id,
                    'code'           => $p->code,
                    'name'           => $p->name,
                    'start_date'     => optional($p->start_date)->format('Y-m-d'),
                    'status'         => $p->status,
                    'status_display' => $p->status_display,
                    'contract_type'  => optional($p->contractType)->name,
                    'sold_hours'     => (float) ($p->sold_hours ?? 0),
                    'consumed_hours' => $consumed,
                ];
            })
            ->sortByDesc('consumed_hours')
            ->values();

        if ($limitParam !== 'all') {
            $n = max(1, (int) $limitParam);
            $items = $items->take($n);
        }

        return response()->json([
            'items' => $items->values(),
            'limit' => $limitParam,
        ]);
    }

    /**
     * Previsto × Consumido MENSAL dos contratos novos de Banco de Horas da Auster
     * (ex.: ATR019-26 Especialista, ATR020-26 Protheus/Fluig). Pedido do Caio (2026-10):
     * previsto mensal = total contratado ÷ 12 meses (contrato anual); consumido = horas
     * apontadas no mês (pai + filhos On Demand, que consomem o banco). Série dos 12 meses
     * a partir do início do contrato. Escopo: contratos PAI BH Fixo iniciados a partir de
     * 09/2026 (os "novos") — os legados (2025) ficam de fora.
     */
    public function monthlyBudget(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;

        $contracts = Project::withoutGlobalScope(HideAusterFrozenScope::class)
            ->with(['contractType'])
            ->where('customer_id', self::AUSTER_CUSTOMER_ID)
            ->whereNull('parent_project_id')
            ->where('sold_hours', '>', 0)
            ->where('start_date', '>=', '2026-09-01')
            ->whereIn('status', [Project::STATUS_STARTED, Project::STATUS_PAUSED])
            ->whereHas('contractType', function ($q) {
                $q->where('code', 'fixed_hours')->orWhereRaw("LOWER(TRIM(name)) = ?", ['banco de horas fixo']);
            })
            ->orderBy('code')
            ->get();

        $months = 12; // contrato anual (decisão: previsto = contratado ÷ 12)
        $items = [];

        foreach ($contracts as $c) {
            if (!$c->start_date) continue;
            $sold    = (float) $c->sold_hours;
            $monthly = round($sold / $months, 2);

            // Banco = pai + filhos que consomem (On Demand ou sold<=0). Filhos-bloco não entram.
            $children = Project::where('parent_project_id', $c->id)
                ->with('contractType:id,name,code')
                ->get(['id', 'contract_type_id', 'sold_hours', 'tipo_faturamento']);
            $childIds = $children->filter(function ($ch) {
                $n = strtolower((string) ($ch->contractType->name ?? ''));
                return str_contains($n, 'on demand') || str_contains($n, 'on-demand')
                    || $ch->tipo_faturamento === 'on_demand' || (float) $ch->sold_hours <= 0;
            })->pluck('id')->all();
            $bankIds = array_merge([$c->id], $childIds);

            $byYm = DB::table('timesheets')
                ->whereIn('project_id', $bankIds)
                ->whereIn('status', ['approved', 'pending'])
                ->whereNull('deleted_at')
                ->selectRaw("to_char(date, 'YYYY-MM') AS ym, COALESCE(SUM(effort_minutes), 0) AS mins")
                ->groupBy('ym')->pluck('mins', 'ym');

            $start  = \Carbon\Carbon::parse($c->start_date)->startOfMonth();
            $rows   = [];
            $cursor = $start->copy();
            $nowYm  = now()->format('Y-m');
            for ($i = 0; $i < $months; $i++) {
                $ym       = $cursor->format('Y-m');
                $consumed = round(((int) ($byYm[$ym] ?? 0)) / 60, 2);
                $rows[] = [
                    'ym'        => $ym,
                    'previsto'  => $monthly,
                    'consumido' => $consumed,
                    'saldo'     => round($monthly - $consumed, 2),
                    'pct'       => $monthly > 0 ? round($consumed / $monthly * 100, 1) : 0.0,
                    'futuro'    => $ym > $nowYm,
                ];
                $cursor->addMonth();
            }
            $totalCons = round(array_sum(array_column($rows, 'consumido')), 2);

            $items[] = [
                'id'             => $c->id,
                'code'           => $c->code,
                'name'           => $c->name,
                'contracted'     => $sold,
                'months'         => $months,
                'monthly_budget' => $monthly,
                'start_date'     => $start->format('Y-m-d'),
                'total_consumed' => $totalCons,
                'total_balance'  => round($sold - $totalCons, 2),
                'rows'           => $rows,
            ];
        }

        return response()->json(['items' => $items]);
    }
}
