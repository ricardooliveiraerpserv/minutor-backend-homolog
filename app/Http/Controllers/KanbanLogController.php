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
        // Ordem/labels das colunas do fluxo.
        $order  = ['backlog', 'planning', 'awaiting_start', 'started', 'liberado_para_testes', 'em_producao', 'paused', 'finished', 'cancelled'];
        $labels = [
            'backlog' => 'Backlog', 'planning' => 'Em Planejamento', 'awaiting_start' => 'Aguardando Início',
            'started' => 'Em Andamento', 'liberado_para_testes' => 'Em Testes', 'em_producao' => 'Em Produção',
            'paused' => 'Pausado', 'finished' => 'Concluído', 'cancelled' => 'Cancelado',
        ];

        $logsByProject = \App\Models\ProjectKanbanLog::orderBy('project_id')->orderBy('created_at')
            ->get(['project_id', 'from_status', 'to_status', 'created_at'])
            ->groupBy('project_id');

        $projects = \App\Models\Project::whereIn('id', $logsByProject->keys())
            ->with('customer:id,name')
            ->get(['id', 'code', 'name', 'customer_id', 'created_at', 'status'])->keyBy('id');

        $daysBetween = fn ($a, $b) => max(0.0, round(\Carbon\Carbon::parse($a)->floatDiffInDays(\Carbon\Carbon::parse($b)), 1));

        $usedCols = [];
        $rows = [];
        foreach ($logsByProject as $pid => $plogs) {
            $proj = $projects->get($pid);
            if (!$proj) continue;
            $plogs = $plogs->values();
            $byCol = [];

            // Coluna inicial (from do 1º log): do início do projeto até o 1º log.
            $first = $plogs->first();
            if ($first->from_status && $proj->created_at) {
                $byCol[$first->from_status] = ($byCol[$first->from_status] ?? 0) + $daysBetween($proj->created_at, $first->created_at);
            }
            // Cada segmento: to_status[i] até o próximo log (ou agora se for o último = coluna atual).
            for ($i = 0; $i < $plogs->count(); $i++) {
                $col = $plogs[$i]->to_status;
                $end = ($i + 1 < $plogs->count()) ? $plogs[$i + 1]->created_at : now();
                $byCol[$col] = ($byCol[$col] ?? 0) + $daysBetween($plogs[$i]->created_at, $end);
            }
            foreach (array_keys($byCol) as $c) $usedCols[$c] = true;
            $rows[] = [
                'project_id'     => (int) $pid,
                'code'           => $proj->code,
                'name'           => $proj->name,
                'customer'       => $proj->customer?->name ?? '—',
                'current'        => $proj->status,
                'days_by_column' => array_map(fn ($d) => round($d, 1), $byCol),
                'total'          => round(array_sum($byCol), 1),
            ];
        }
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        // Só as colunas que apareceram, na ordem do fluxo (+ eventuais fora da lista, ao fim).
        $columns = array_values(array_filter($order, fn ($c) => isset($usedCols[$c])));
        foreach (array_keys($usedCols) as $c) if (!in_array($c, $columns, true)) $columns[] = $c;

        return response()->json([
            'columns' => array_map(fn ($c) => ['key' => $c, 'label' => $labels[$c] ?? $c], $columns),
            'rows'    => $rows,
        ]);
    }
}
