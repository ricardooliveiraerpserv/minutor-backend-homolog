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
}
