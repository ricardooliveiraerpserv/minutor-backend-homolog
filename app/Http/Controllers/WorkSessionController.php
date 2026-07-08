<?php

namespace App\Http\Controllers;

use App\Models\WorkSession;
use App\Models\WorkSessionEvent;
use App\Services\WorkSessionSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sessão de Trabalho — endpoints de PLATAFORMA (reutilizáveis por help_desk, comercial, etc.).
 * Persiste sessão + eventos (durável p/ IA futura). A vivência (fila/posição/timers) é client-side.
 */
class WorkSessionController extends Controller
{
    public function __construct(private WorkSessionSummaryService $summaries)
    {
    }

    public function start(Request $request): JsonResponse
    {
        $v = $request->validate(['scope' => 'required|string|max:30', 'context' => 'nullable|array']);
        $s = WorkSession::create([
            'scope' => $v['scope'], 'user_id' => $request->user()->id,
            'started_at' => now(), 'context' => $v['context'] ?? [],
        ]);
        return response()->json(['data' => ['id' => $s->id, 'started_at' => $s->started_at->toIso8601String()]], 201);
    }

    public function event(Request $request, WorkSession $session): JsonResponse
    {
        abort_unless((int) $session->user_id === (int) $request->user()->id, 403);
        $v = $request->validate([
            'type'        => 'required|string|max:40',
            'entity_type' => 'nullable|string|max:40',
            'entity_id'   => 'nullable|integer',
            'metadata'    => 'nullable|array',
        ]);
        WorkSessionEvent::create([
            'work_session_id' => $session->id, 'type' => $v['type'],
            'entity_type' => $v['entity_type'] ?? null, 'entity_id' => $v['entity_id'] ?? null,
            'metadata' => $v['metadata'] ?? null, 'created_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }

    public function end(Request $request, WorkSession $session): JsonResponse
    {
        abort_unless((int) $session->user_id === (int) $request->user()->id, 403);
        if (!$session->ended_at) $session->update(['ended_at' => now()]);
        return response()->json(['data' => $this->buildSummary($session)]);
    }

    public function summary(Request $request, WorkSession $session): JsonResponse
    {
        abort_unless((int) $session->user_id === (int) $request->user()->id, 403);
        return response()->json(['data' => $this->buildSummary($session)]);
    }

    /** Indicadores da sessão — delega ao service compartilhado (mesma regra usada pela Central de Operações). */
    private function buildSummary(WorkSession $session): array
    {
        return $this->summaries->summarize($session);
    }
}
