<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Duas visões de gestão da equipe (consultores):
 *  - allocation(): "quem está vinculado a cada projeto" — horas planejadas (contratadas),
 *    consumidas (apontadas) e saldo, por (projeto × consultor). Mesma fonte do team_load
 *    do cronograma (planejadas = SUM stage_deliveries.hours_planned do responsável;
 *    consumidas = timesheets approved/pending/released).
 *  - weekly(): "apontamento feito por semana" — horas apontadas por (semana × consultor × projeto).
 *
 * Sem custo (R$). Sem escopo por cliente (rota já bloqueia cliente).
 */
class ConsultantWorkloadController extends Controller
{
    /** Universo de projetos: só tipo "Projeto", em execução (sem encerrado/cancelado), não investimento. */
    private function baseProjectQuery(Request $request)
    {
        $q = Project::query()
            ->whereHas('serviceType', fn ($s) => $s->whereRaw('LOWER(name) = ?', ['projeto']))
            ->where(fn ($x) => $x->whereNull('is_investimento_comercial')->orWhere('is_investimento_comercial', false));

        // Por padrão, em execução. status=all traz encerrados/cancelados também.
        if ($request->get('status') !== 'all') {
            $q->whereNotIn('status', [Project::STATUS_FINISHED, Project::STATUS_CANCELLED]);
        }
        if ($pid = $request->get('project_id')) $q->where('id', $pid);
        if ($cid = $request->get('customer_id')) $q->where('customer_id', $cid);
        if ($coordId = $request->get('coordinator_id')) {
            $q->whereHas('coordinators', fn ($c) => $c->where('users.id', $coordId));
        }
        return $q;
    }

    /**
     * Visão 1 — Alocação por consultor: linhas (projeto × consultor) com
     * horas contratadas (planejadas), consumidas (apontadas) e saldo.
     */
    public function allocation(Request $request): JsonResponse
    {
        $projects = $this->baseProjectQuery($request)
            ->with(['customer:id,name'])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'status', 'customer_id']);
        $projectIds = $projects->pluck('id')->all();
        if (empty($projectIds)) return response()->json(['rows' => []]);

        // Planejadas (contratadas) por projeto × responsável de atividade.
        $planned = DB::table('stage_deliveries as sd')
            ->join('project_stages as ps', 'ps.id', '=', 'sd.stage_id')
            ->whereIn('ps.project_id', $projectIds)
            ->whereNull('ps.deleted_at')->whereNull('sd.deleted_at')
            ->whereNotNull('sd.responsible_user_id')
            ->groupBy('ps.project_id', 'sd.responsible_user_id')
            ->selectRaw('ps.project_id, sd.responsible_user_id AS user_id, COALESCE(SUM(sd.hours_planned),0) AS h')
            ->get();

        // Consumidas (apontadas) por projeto × usuário (approved/pending/released).
        $actual = DB::table('timesheets')
            ->whereIn('project_id', $projectIds)
            ->whereNull('deleted_at')
            ->whereIn('status', [Timesheet::STATUS_APPROVED, Timesheet::STATUS_PENDING, Timesheet::STATUS_RELEASED])
            ->groupBy('project_id', 'user_id')
            ->selectRaw('project_id, user_id, COALESCE(SUM(effort_minutes),0)/60.0 AS h')
            ->get();

        // Índices por (project_id,user_id).
        $key = fn ($p, $u) => $p . ':' . $u;
        $plannedMap = [];
        foreach ($planned as $r) $plannedMap[$key($r->project_id, $r->user_id)] = (float) $r->h;
        $actualMap = [];
        foreach ($actual as $r) $actualMap[$key($r->project_id, $r->user_id)] = (float) $r->h;

        // Universo de consultores = quem é responsável por atividade OU apontou no projeto.
        $pairs = [];
        foreach ($planned as $r) $pairs[$key($r->project_id, $r->user_id)] = ['p' => $r->project_id, 'u' => $r->user_id];
        foreach ($actual as $r)  $pairs[$key($r->project_id, $r->user_id)] = ['p' => $r->project_id, 'u' => $r->user_id];

        $userIds = array_values(array_unique(array_map(fn ($x) => $x['u'], $pairs)));
        $users = User::whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');
        $projById = $projects->keyBy('id');

        $rows = [];
        foreach ($pairs as $pair) {
            $p = $projById->get($pair['p']);
            $u = $users->get($pair['u']);
            if (!$p || !$u) continue;
            $planH = round($plannedMap[$key($pair['p'], $pair['u'])] ?? 0, 2);
            $actH  = round($actualMap[$key($pair['p'], $pair['u'])] ?? 0, 2);
            $rows[] = [
                'project_id'    => $p->id,
                'project_code'  => $p->code,
                'project_name'  => $p->name,
                'project_status' => $p->status,
                'customer_name' => $p->customer->name ?? null,
                'user_id'       => $u->id,
                'user_name'     => $u->name,
                'role'          => 'Consultor',
                'planned_hours' => $planH,
                'consumed_hours' => $actH,
                'balance_hours' => round($planH - $actH, 2),
            ];
        }
        // Ordena por projeto, depois consultor.
        usort($rows, fn ($a, $b) => [$a['project_name'], $a['user_name']] <=> [$b['project_name'], $b['user_name']]);

        return response()->json(['rows' => $rows]);
    }

    /**
     * Visão 2 — Apontamento semanal: horas apontadas por (semana × consultor × projeto).
     * Params: project_id? (filtra 1 projeto), weeks (default 8), coordinator_id?, customer_id?.
     * Semana = segunda-feira (date_trunc('week') do Postgres). Só linhas com horas > 0.
     */
    public function weekly(Request $request): JsonResponse
    {
        $projects = $this->baseProjectQuery($request)->get(['id', 'code', 'name']);
        $projectIds = $projects->pluck('id')->all();
        if (empty($projectIds)) return response()->json(['rows' => [], 'weeks' => []]);

        $weeks = max(1, min(52, (int) $request->get('weeks', 8)));
        $fromWeek = Carbon::now()->startOfWeek()->subWeeks($weeks - 1)->toDateString();

        $rows = DB::table('timesheets')
            ->whereIn('project_id', $projectIds)
            ->whereNull('deleted_at')
            ->whereIn('status', [Timesheet::STATUS_APPROVED, Timesheet::STATUS_PENDING, Timesheet::STATUS_RELEASED])
            ->whereRaw("date >= ?", [$fromWeek])
            ->groupByRaw("date_trunc('week', date), user_id, project_id")
            ->selectRaw("date_trunc('week', date)::date AS week_start, user_id, project_id, COALESCE(SUM(effort_minutes),0)/60.0 AS h")
            ->get();

        $userIds = $rows->pluck('user_id')->unique()->all();
        $users = User::whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');
        $projById = $projects->keyBy('id');

        $out = [];
        foreach ($rows as $r) {
            $u = $users->get($r->user_id);
            $p = $projById->get($r->project_id);
            if (!$u || !$p) continue;
            $out[] = [
                'week_start'   => (string) $r->week_start,
                'user_id'      => $r->user_id,
                'user_name'    => $u->name,
                'project_id'   => $r->project_id,
                'project_code' => $p->code,
                'project_name' => $p->name,
                'hours'        => round((float) $r->h, 2),
            ];
        }
        // Semana desc, depois consultor, depois projeto.
        usort($out, fn ($a, $b) => [$b['week_start'], $a['user_name'], $a['project_name']] <=> [$a['week_start'], $b['user_name'], $b['project_name']]);

        // Lista de semanas (segundas) do range, mais recente primeiro — p/ o cabeçalho da grade.
        $weekList = [];
        for ($i = 0; $i < $weeks; $i++) {
            $weekList[] = Carbon::now()->startOfWeek()->subWeeks($i)->toDateString();
        }

        return response()->json(['rows' => $out, 'weeks' => $weekList]);
    }
}
