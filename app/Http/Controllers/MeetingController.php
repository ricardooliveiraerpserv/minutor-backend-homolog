<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central de Reunião. Módulo p/ admin+coordenador. Cada reunião só é visível aos
 * ENVOLVIDOS (participantes) + criador; admin vê tudo. Tarefas reusam a tabela
 * `tasks` (entity_type='meeting') → aparecem em "Minhas tarefas" do responsável.
 */
class MeetingController extends Controller
{
    private const MANAGERS = ['admin', 'coordenador'];

    private function manager(Request $request): User
    {
        $u = $request->user();
        abort_unless($u && in_array($u->type, self::MANAGERS, true), 403);
        return $u;
    }

    private function findVisible(User $u, int $id): Meeting
    {
        $m = Meeting::findOrFail($id);
        abort_unless($m->isVisibleTo($u), 403, 'Você não participa desta reunião.');
        return $m;
    }

    public function index(Request $request): JsonResponse
    {
        $u = $this->manager($request);
        $rows = Meeting::visibleTo($u)
            ->with(['creator:id,name', 'participants:id,name'])
            ->orderByRaw('meeting_date is null')
            ->orderByDesc('meeting_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'meeting_date' => optional($m->meeting_date)->toIso8601String(),
                'location' => $m->location,
                'creator' => $m->creator?->name,
                'participants' => $m->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
                'participants_count' => $m->participants->count(),
                'tasks_count' => Task::where('entity_type', 'meeting')->where('entity_id', $m->id)->count(),
                'open_tasks_count' => Task::where('entity_type', 'meeting')->where('entity_id', $m->id)->where('completed', false)->count(),
            ]);
        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $meeting): JsonResponse
    {
        $u = $this->manager($request);
        $m = $this->findVisible($u, $meeting);
        $m->load(['creator:id,name', 'participants:id,name']);
        return response()->json(['data' => $this->serialize($m, $u)]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->manager($request);
        $v = $request->validate([
            'title'            => 'required|string|max:250',
            'meeting_date'     => 'nullable|date',
            'location'         => 'nullable|string|max:250',
            'description'      => 'nullable|string',
            'notes'            => 'nullable|string',
            'participant_ids'  => 'nullable|array',
            'participant_ids.*'=> 'integer|exists:users,id',
        ]);
        $m = Meeting::create([
            'title' => trim($v['title']),
            'meeting_date' => $v['meeting_date'] ?? null,
            'location' => $v['location'] ?? null,
            'description' => $v['description'] ?? null,
            'notes' => $v['notes'] ?? null,
            'created_by_id' => $u->id,
        ]);
        // criador sempre é participante; + os selecionados
        $ids = collect($v['participant_ids'] ?? [])->push($u->id)->unique()->values()->all();
        $m->participants()->sync($ids);
        $m->load(['creator:id,name', 'participants:id,name']);
        return response()->json(['data' => $this->serialize($m, $u)], 201);
    }

    public function update(Request $request, int $meeting): JsonResponse
    {
        $u = $this->manager($request);
        $m = $this->findVisible($u, $meeting);
        $v = $request->validate([
            'title'        => 'sometimes|string|max:250',
            'meeting_date' => 'nullable|date',
            'location'     => 'nullable|string|max:250',
            'description'  => 'nullable|string',
            'notes'        => 'nullable|string',
        ]);
        $m->update($v);
        $m->load(['creator:id,name', 'participants:id,name']);
        return response()->json(['data' => $this->serialize($m, $u)]);
    }

    public function destroy(Request $request, int $meeting): JsonResponse
    {
        $u = $this->manager($request);
        $m = Meeting::findOrFail($meeting);
        abort_unless($u->isAdmin() || $m->created_by_id === $u->id, 403, 'Apenas o criador ou um admin pode excluir a reunião.');
        $m->delete();
        return response()->json(['ok' => true]);
    }

    /** Sincroniza a lista de participantes (envolvidos). O criador permanece sempre. */
    public function syncParticipants(Request $request, int $meeting): JsonResponse
    {
        $u = $this->manager($request);
        $m = $this->findVisible($u, $meeting);
        $v = $request->validate(['participant_ids' => 'present|array', 'participant_ids.*' => 'integer|exists:users,id']);
        $ids = collect($v['participant_ids'])->push($m->created_by_id)->filter()->unique()->values()->all();
        $m->participants()->sync($ids);
        $m->load(['creator:id,name', 'participants:id,name']);
        return response()->json(['data' => $this->serialize($m, $u)]);
    }

    /** Cria tarefa da reunião (entity_type=meeting) atribuída a um participante. */
    public function storeTask(Request $request, int $meeting): JsonResponse
    {
        $u = $this->manager($request);
        $m = $this->findVisible($u, $meeting);
        $v = $request->validate([
            'title'       => 'required|string|max:250',
            'description' => 'nullable|string',
            'assigned_to' => 'required|integer|exists:users,id',
            'due_date'    => 'nullable|date',
        ]);
        // o responsável deve ser um envolvido na reunião
        $participantIds = $m->participants()->pluck('users.id')->push($m->created_by_id)->unique();
        abort_unless($participantIds->contains((int) $v['assigned_to']), 422, 'O responsável precisa ser um participante da reunião.');

        $t = Task::create([
            'user_id'     => $u->id,
            'created_by'  => $u->id,
            'assigned_to' => (int) $v['assigned_to'],
            'title'       => trim($v['title']),
            'description' => $v['description'] ?? null,
            'due_date'    => $v['due_date'] ?? null,
            'completed'   => false,
            'entity_type' => 'meeting',
            'entity_id'   => $m->id,
        ]);
        return response()->json(['data' => $this->serializeTask($t->fresh(['assignee', 'creator']))], 201);
    }

    public function deleteTask(Request $request, int $meeting, int $task): JsonResponse
    {
        $u = $this->manager($request);
        $m = $this->findVisible($u, $meeting);
        $t = Task::where('entity_type', 'meeting')->where('entity_id', $m->id)->findOrFail($task);
        abort_unless($u->isAdmin() || $t->created_by === $u->id, 403, 'Apenas quem criou a tarefa (ou admin) pode removê-la.');
        $t->delete();
        return response()->json(['ok' => true]);
    }

    /** Concluir/reabrir tarefa da reunião — só o responsável. */
    public function toggleTask(Request $request, int $meeting, int $task): JsonResponse
    {
        $u = $this->manager($request);
        $m = $this->findVisible($u, $meeting);
        $t = Task::where('entity_type', 'meeting')->where('entity_id', $m->id)->findOrFail($task);
        abort_unless($t->assigned_to === $u->id || $u->isAdmin(), 403, 'Apenas o responsável pode concluir/reabrir.');
        $done = !$t->completed;
        $t->update(['completed' => $done, 'completed_at' => $done ? now() : null, 'completed_by' => $done ? $u->id : null]);
        return response()->json(['data' => $this->serializeTask($t->fresh(['assignee', 'creator']))]);
    }

    private function serialize(Meeting $m, User $u): array
    {
        $tasks = Task::where('entity_type', 'meeting')->where('entity_id', $m->id)
            ->with(['assignee:id,name', 'creator:id,name'])->orderBy('completed')->orderByRaw('due_date is null')->orderBy('due_date')->get();
        return [
            'id' => $m->id,
            'title' => $m->title,
            'meeting_date' => optional($m->meeting_date)->toIso8601String(),
            'location' => $m->location,
            'description' => $m->description,
            'notes' => $m->notes,
            'creator' => $m->creator?->name,
            'created_by_id' => $m->created_by_id,
            'can_delete' => $u->isAdmin() || $m->created_by_id === $u->id,
            'participants' => $m->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'tasks' => $tasks->map(fn ($t) => $this->serializeTask($t))->values(),
        ];
    }

    private function serializeTask(Task $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'assigned_to' => $t->assigned_to,
            'assignee_name' => $t->assignee?->name,
            'created_by' => $t->created_by,
            'due_date' => optional($t->due_date)->format('Y-m-d'),
            'completed' => (bool) $t->completed,
        ];
    }
}
