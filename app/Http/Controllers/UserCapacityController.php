<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserCapacityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserCapacityController extends Controller
{
    /**
     * GET /users/{user}/capacity — capacidade global do consultor cross-projetos.
     * Lista alocações ativas (etapa não concluída, projeto operacional) com
     * planned/actual/remaining e totais. `overload` é derivado.
     */
    public function show(User $user, Request $request): JsonResponse
    {
        $capacity = $user->capacity_hours !== null
            ? (float) $user->capacity_hours
            : UserCapacityService::DEFAULT_CAPACITY_HOURS;

        $summary = UserCapacityService::summarize($user->id, $capacity);

        // Opcional ?from=YYYY-MM-DD&to=YYYY-MM-DD — resumo restrito a período
        // (sem params = comportamento global preservado).
        $from = $request->query('from');
        $to   = $request->query('to');
        $extra = [];
        if ($from && $to) {
            $extra['period'] = UserCapacityService::periodSummary($user->id, $from, $to);
        }

        return response()->json(array_merge(
            ['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]],
            $summary,
            $extra,
        ));
    }

    /**
     * GET /users/capacity — lista de todos os consultores com flag de sobrecarga.
     * Ordenada por overload primeiro, depois por consumo % decrescente.
     */
    public function index(Request $request): JsonResponse
    {
        // Filtro opcional ?ids=12,17,23 — pra páginas como Cronograma que só
        // precisam dos responsáveis listados (evita iterar todos os consultores).
        $idsParam = (string) $request->query('ids', '');
        $ids = collect(explode(',', $idsParam))
            ->map(fn ($s) => (int) trim($s))
            ->filter()
            ->values()
            ->all();

        $users = User::query()
            ->where('type', 'consultor')
            ->when(!empty($ids), fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'capacity_hours']);

        // Carga por mês de todos de uma vez (base da "ocupação por mês", sem N+1).
        $monthlyAll = UserCapacityService::monthlyLoadByUser($users->pluck('id')->all());

        $items = $users->map(function (User $u) use ($monthlyAll) {
            $capacity = $u->capacity_hours !== null
                ? (float) $u->capacity_hours
                : UserCapacityService::DEFAULT_CAPACITY_HOURS;

            $summary = UserCapacityService::summarize($u->id, $capacity);

            // Ocupação = MÊS MAIS CHEIO (planejado no mês ÷ capacidade mensal). O total (backlog)
            // pode estar espalhado por vários meses — comparar o total com 1 mês dava falso overload.
            $monthly   = $monthlyAll[$u->id] ?? [];
            $peakHours = !empty($monthly) ? max($monthly) : 0.0;
            $peakMonth = !empty($monthly) ? (string) array_keys($monthly, $peakHours)[0] : null;

            $usagePct = $capacity > 0 ? round($peakHours / $capacity * 100, 1) : 0.0;
            $overload = $peakHours > $capacity;
            $reasons  = $overload && $peakMonth
                ? [sprintf('Pico de %.0fh em %s excede a capacidade de %.0fh/mês', $peakHours, $peakMonth, $capacity)]
                : [];

            return [
                'user' => [
                    'id'    => $u->id,
                    'name'  => $u->name,
                    'email' => $u->email,
                ],
                'capacity_hours'   => $capacity,
                'planned_hours'    => $summary['totals']['planned_hours'], // backlog total (todos os meses)
                'actual_hours'     => $summary['totals']['actual_hours'],
                'remaining_hours'  => $summary['totals']['remaining_hours'],
                'usage_pct'        => $usagePct,          // ocupação do mês mais cheio
                'peak_month'       => $peakMonth,         // 'YYYY-MM'
                'peak_month_hours' => round($peakHours, 2),
                'allocation_count' => count($summary['items']),
                'overload'         => $overload,
                'overload_reasons' => $reasons,
            ];
        });

        $sorted = $items
            ->sortByDesc(fn ($i) => [$i['overload'] ? 1 : 0, $i['usage_pct']])
            ->values();

        return response()->json([
            'items'   => $sorted,
            'summary' => [
                'overloaded_count' => $sorted->where('overload', true)->count(),
                'total_count'      => $sorted->count(),
            ],
        ]);
    }
}
