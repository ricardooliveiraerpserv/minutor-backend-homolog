<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class KanbanLogController extends Controller
{
    public function contractLogs(\App\Models\Contract $contract): JsonResponse
    {
        $logs = \App\Models\ContractKanbanLog::with('movedBy:id,name')
            ->where('contract_id', $contract->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($l) => [
                'id'         => $l->id,
                'from_column'=> $l->from_column,
                'to_column'  => $l->to_column,
                'moved_by'   => $l->movedBy?->name ?? '—',
                'created_at' => $l->created_at?->toISOString(),
            ]);

        return response()->json($logs);
    }

    public function projectLogs(\App\Models\Project $project): JsonResponse
    {
        $logs = \App\Models\ProjectKanbanLog::with('movedBy:id,name')
            ->where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($l) => [
                'id'          => $l->id,
                'from_status' => $l->from_status,
                'to_status'   => $l->to_status,
                'moved_by'    => $l->movedBy?->name ?? '—',
                'created_at'  => $l->created_at?->toISOString(),
            ]);

        return response()->json($logs);
    }

    public function requestLogs(\App\Models\ContractRequest $contractRequest): JsonResponse
    {
        $logs = \App\Models\ContractRequestKanbanLog::with('movedBy:id,name')
            ->where('contract_request_id', $contractRequest->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($l) => [
                'id'         => $l->id,
                'from_column'=> $l->from_column,
                'to_column'  => $l->to_column,
                'moved_by'   => $l->movedBy?->name ?? '—',
                'created_at' => $l->created_at?->toISOString(),
            ]);

        return response()->json($logs);
    }

    /**
     * Histórico de DIAS por COLUNA de cada projeto (pipeline Demandas e Projetos).
     * A partir das transições (project_kanban_logs), soma quanto tempo cada projeto
     * passou em cada coluna. Coluna atual conta até hoje. GET /projects/kanban-column-history
     */
    public function columnHistory(\Illuminate\Http\Request $request): JsonResponse
    {
        // Mapeia STATUS do projeto → COLUNA do pipeline Demandas e Projetos (agrupa backlog+awaiting_start).
        $statusToCol = [
            'backlog' => 'backlog', 'awaiting_start' => 'backlog',
            'planning' => 'planning', 'started' => 'started',
            'liberado_para_testes' => 'homologacao', 'em_producao' => 'em_producao',
            'paused' => 'paused', 'finished' => 'finished', 'cancelled' => 'cancelled',
        ];
        // Ordem/labels iguais às colunas do pipeline.
        $order  = ['backlog', 'planning', 'started', 'homologacao', 'em_producao', 'paused', 'finished', 'cancelled'];
        $labels = [
            'backlog' => 'Backlog', 'planning' => 'Em Planejamento', 'started' => 'Em Andamento',
            'homologacao' => 'Em Homologação', 'em_producao' => 'Em Produção',
            'paused' => 'Pausado', 'finished' => 'Encerrado', 'cancelled' => 'Cancelado',
        ];
        // Terminais: quando o projeto está PARADO nelas (coluna atual), o cronômetro NÃO corre.
        $terminal = ['finished', 'cancelled'];

        $logsByProject = \App\Models\ProjectKanbanLog::orderBy('project_id')->orderBy('created_at')
            ->get(['project_id', 'from_status', 'to_status', 'created_at'])
            ->groupBy('project_id');

        // Só projetos do pipeline Demandas e Projetos (categoria projeto) — exclui SUSTENTAÇÃO/CLOUD.
        $projects = \App\Models\Project::whereIn('id', $logsByProject->keys())
            ->whereDoesntHave('serviceType', fn ($q) => $q->whereIn('code', ['sustentacao', 'cloud']))
            ->with('customer:id,name')
            ->get(['id', 'code', 'name', 'customer_id', 'created_at', 'status', 'service_type_id'])->keyBy('id');

        $daysBetween = fn ($a, $b) => max(0.0, round(\Carbon\Carbon::parse($a)->floatDiffInDays(\Carbon\Carbon::parse($b)), 1));
        $col = fn ($status) => $statusToCol[$status] ?? $status;

        $usedCols = [];
        $rows = [];
        foreach ($logsByProject as $pid => $plogs) {
            $proj = $projects->get($pid);
            if (!$proj) continue;   // filtrado (sustentação) → fora
            $plogs = $plogs->values();
            $byCol = [];

            // Coluna inicial (from do 1º log): do início do projeto até o 1º log.
            $first = $plogs->first();
            if ($first->from_status && $proj->created_at) {
                $c = $col($first->from_status);
                $byCol[$c] = ($byCol[$c] ?? 0) + $daysBetween($proj->created_at, $first->created_at);
            }
            // Cada segmento: to_status[i] até o próximo log (ou agora se for o último = coluna atual).
            $n = $plogs->count();
            for ($i = 0; $i < $n; $i++) {
                $status = $plogs[$i]->to_status;
                $isLast = ($i + 1 >= $n);
                // Coluna atual em Encerrado/Cancelado: para de contar (não soma o segmento aberto).
                if ($isLast && in_array($status, $terminal, true)) continue;
                $end = $isLast ? now() : $plogs[$i + 1]->created_at;
                $c = $col($status);
                $byCol[$c] = ($byCol[$c] ?? 0) + $daysBetween($plogs[$i]->created_at, $end);
            }
            foreach (array_keys($byCol) as $c) $usedCols[$c] = true;
            $rows[] = [
                'project_id'     => (int) $pid,
                'code'           => $proj->code,
                'name'           => $proj->name,
                'customer'       => $proj->customer?->name ?? '—',
                'current'        => $proj->status,
                'current_label'  => $labels[$col($proj->status)] ?? $proj->status,
                'days_by_column' => array_map(fn ($d) => round($d, 1), $byCol),
                'total'          => round(array_sum($byCol), 1),
            ];
        }
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        // Colunas usadas, na ordem do pipeline.
        $columns = array_values(array_filter($order, fn ($c) => isset($usedCols[$c])));
        foreach (array_keys($usedCols) as $c) if (!in_array($c, $columns, true)) $columns[] = $c;

        return response()->json([
            'columns' => array_map(fn ($c) => ['key' => $c, 'label' => $labels[$c] ?? $c], $columns),
            'rows'    => $rows,
        ]);
    }
}
