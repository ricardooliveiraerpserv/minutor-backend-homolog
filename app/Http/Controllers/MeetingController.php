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
            'title'         => 'required|string|max:5000',
            'description'   => 'nullable|string',
            'assigned_to'   => 'required|array|min:1',       // múltiplos responsáveis
            'assigned_to.*' => 'integer|exists:users,id',
            'due_date'      => 'required|date',              // prazo obrigatório
        ]);
        $assignees = $this->validateAssignees($m, $v['assigned_to']);

        $t = Task::create([
            'user_id'     => $u->id,
            'created_by'  => $u->id,
            'assigned_to' => $assignees[0],                  // principal (compat Minhas Tarefas/Calendário)
            'title'       => trim($v['title']),
            'description' => $v['description'] ?? null,
            'due_date'    => $v['due_date'],
            'completed'   => false,
            'entity_type' => 'meeting',
            'entity_id'   => $m->id,
        ]);
        $t->assignees()->sync($assignees);                  // todos (inclui o principal)
        return response()->json(['data' => $this->serializeTask($t->fresh(['assignee', 'creator', 'assignees:id,name']))], 201);
    }

    /** Editar tarefa da reunião (título/responsáveis/prazo) — criador ou admin. */
    public function updateTask(Request $request, int $meeting, int $task): JsonResponse
    {
        $u = $this->manager($request);
        $m = $this->findVisible($u, $meeting);
        $t = Task::where('entity_type', 'meeting')->where('entity_id', $m->id)->findOrFail($task);
        abort_unless($u->isAdmin() || $t->created_by === $u->id, 403, 'Apenas quem criou a tarefa (ou admin) pode editá-la.');

        $v = $request->validate([
            'title'         => 'required|string|max:5000',
            'assigned_to'   => 'required|array|min:1',
            'assigned_to.*' => 'integer|exists:users,id',
            'due_date'      => 'required|date',
        ]);
        $assignees = $this->validateAssignees($m, $v['assigned_to']);

        $t->update([
            'title'       => trim($v['title']),
            'assigned_to' => $assignees[0],
            'due_date'    => $v['due_date'],
        ]);
        $t->assignees()->sync($assignees);
        return response()->json(['data' => $this->serializeTask($t->fresh(['assignee', 'creator', 'assignees:id,name']))]);
    }

    /** Normaliza + valida que TODOS os responsáveis são participantes da reunião. Retorna ids únicos. */
    private function validateAssignees(Meeting $m, array $ids): array
    {
        $ids = collect($ids)->map(fn ($i) => (int) $i)->filter()->unique()->values();
        abort_if($ids->isEmpty(), 422, 'Informe ao menos um responsável.');
        $participantIds = $m->participants()->pluck('users.id')->push($m->created_by_id)->unique();
        foreach ($ids as $id) {
            abort_unless($participantIds->contains($id), 422, 'Todo responsável precisa ser um participante da reunião.');
        }
        return $ids->all();
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

    /** Concluir/reabrir tarefa da reunião — QUALQUER responsável (conclui p/ todos) ou admin. */
    public function toggleTask(Request $request, int $meeting, int $task): JsonResponse
    {
        $u = $this->manager($request);
        $m = $this->findVisible($u, $meeting);
        $t = Task::where('entity_type', 'meeting')->where('entity_id', $m->id)->with('assignees:id')->findOrFail($task);
        abort_unless($u->isAdmin() || in_array($u->id, $t->allAssigneeIds(), true), 403, 'Apenas um responsável pode concluir/reabrir.');
        $done = !$t->completed;
        // Tarefa é ÚNICA: concluir marca a task inteira → conclui p/ TODOS os responsáveis.
        $t->update(['completed' => $done, 'completed_at' => $done ? now() : null, 'completed_by' => $done ? $u->id : null]);
        return response()->json(['data' => $this->serializeTask($t->fresh(['assignee', 'creator', 'assignees:id,name']))]);
    }

    /**
     * Lista consolidada de tarefas de reunião PENDENTES (todas as reuniões visíveis),
     * agrupadas por responsável, com a reunião de origem (p/ direcionar) e filtro por reunião.
     * GET /meetings/tasks/pending?meeting_id=&include_done=0
     */
    public function pendingTasks(Request $request): JsonResponse
    {
        $u = $this->manager($request);
        $meetingIds = Meeting::visibleTo($u)->pluck('id');       // respeita visibilidade (admin vê tudo)

        $q = Task::where('entity_type', 'meeting')
            ->whereIn('entity_id', $meetingIds)
            ->with(['assignees:id,name', 'assignee:id,name']);
        if ($request->filled('meeting_id')) {
            $q->where('entity_id', (int) $request->query('meeting_id'));
        }
        if (!$request->boolean('include_done')) {
            $q->where('completed', false);
        }
        $tasks = $q->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('id')->get();

        $meetingsById = Meeting::whereIn('id', $tasks->pluck('entity_id')->unique())
            ->get(['id', 'title', 'meeting_date'])->keyBy('id');

        // Agrupa por RESPONSÁVEL (uma tarefa com N responsáveis aparece p/ cada um).
        $byUser = [];
        foreach ($tasks as $t) {
            $m = $meetingsById->get($t->entity_id);
            $row = [
                'task_id'       => $t->id,
                'title'         => $t->title,
                'due_date'      => optional($t->due_date)->format('Y-m-d'),
                'completed'     => (bool) $t->completed,
                'meeting_id'    => $t->entity_id,
                'meeting_title' => $m?->title ?? '—',
                'assignees'     => $this->assigneeList($t),
            ];
            foreach ($this->assigneeList($t) as $a) {
                $byUser[$a['id']] ??= ['user_id' => $a['id'], 'user_name' => $a['name'], 'tasks' => []];
                $byUser[$a['id']]['tasks'][] = $row;
            }
        }
        // Ordena grupos por nome; sem responsável ("—") ao fim.
        $groups = collect($byUser)->sortBy('user_name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        // Reuniões (p/ o filtro do FE).
        $meetingOptions = Meeting::visibleTo($u)->orderByDesc('id')->get(['id', 'title'])
            ->map(fn ($m) => ['id' => $m->id, 'title' => $m->title])->values();

        return response()->json(['data' => ['groups' => $groups, 'meetings' => $meetingOptions]]);
    }

    /** Lista de responsáveis (pivot ∪ assigned_to) já como [{id,name}]. */
    private function assigneeList(Task $t): array
    {
        $names = [];
        foreach ($t->relationLoaded('assignees') ? $t->assignees : $t->assignees()->get(['users.id', 'name']) as $a) {
            $names[$a->id] = $a->name;
        }
        if ($t->assigned_to && !isset($names[$t->assigned_to])) {
            $names[$t->assigned_to] = $t->assignee?->name ?? '—';
        }
        return collect($names)->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])->values()->all();
    }

    private function serialize(Meeting $m, User $u): array
    {
        $tasks = Task::where('entity_type', 'meeting')->where('entity_id', $m->id)
            ->with(['assignee:id,name', 'creator:id,name', 'assignees:id,name'])->orderBy('completed')->orderByRaw('due_date is null')->orderBy('due_date')->get();
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
        $assignees = $this->assigneeList($t);
        return [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'assigned_to' => $t->assigned_to,                                   // principal (compat)
            'assignee_name' => $t->assignee?->name,
            'assignees' => $assignees,                                          // TODOS os responsáveis [{id,name}]
            'assignee_ids' => array_map(fn ($a) => $a['id'], $assignees),
            'created_by' => $t->created_by,
            'due_date' => optional($t->due_date)->format('Y-m-d'),
            'completed' => (bool) $t->completed,
        ];
    }
}
