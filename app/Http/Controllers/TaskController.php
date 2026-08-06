<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tarefas rápidas (Smart To-Do) pessoais. Tudo escopado ao próprio usuário. */
class TaskController extends Controller
{
    /** Tarefas: pendentes + concluídas hoje, por filtro de delegação (mine|by_me|to_me). */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        $filter = (string) $request->query('filter', 'mine');
        $completed = $request->boolean('completed');

        $base = Task::query()
            ->with(['creator:id,name', 'assignee:id,name', 'completer:id,name'])
            ->where(function ($q) use ($u, $filter) {
                // "sou responsável" = principal (assigned_to) OU um dos múltiplos (pivot task_assignees).
                $isMine = fn ($w) => $w->where('assigned_to', $u->id)
                    ->orWhereHas('assignees', fn ($a) => $a->where('users.id', $u->id));
                match ($filter) {
                    'by_me' => $q->where('created_by', $u->id)->where('assigned_to', '!=', $u->id),       // delegadas por mim
                    'to_me' => $q->where('created_by', '!=', $u->id)->where($isMine),                      // delegadas para mim
                    default => $q->where($isMine),                                                          // minhas (sou responsável)
                };
            });

        if ($completed) {
            // Concluídas recentes (últimos 30 dias), mais novas primeiro.
            $rows = $base->where('completed', true)
                ->whereDate('completed_at', '>=', now()->subDays(30)->toDateString())
                ->orderByDesc('completed_at')->limit(100)->get();
        } else {
            // Pendentes — concluir faz a tarefa SAIR daqui (vai p/ a lista de concluídas).
            $rows = $base->where('completed', false)
                ->orderByRaw("case priority when 'alta' then 3 when 'media' then 2 else 1 end desc")
                ->orderByRaw('due_date is null')
                ->orderBy('due_date')
                ->orderByRaw('due_time is null')
                ->orderBy('due_time')
                ->orderByDesc('id')->get();
        }

        return response()->json(['data' => $rows->map(fn (Task $t) => $this->serialize($t, $u))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        $v = $this->validatePayload($request, true);
        $assignee = (int) ($v['assigned_to'] ?? $u->id);
        if ($assignee !== $u->id && !$this->canDelegate($u)) abort(403, 'Você não tem permissão para delegar tarefas.');
        $t = Task::create(array_merge($v, [
            'user_id'     => $u->id,
            'created_by'  => $u->id,
            'assigned_to' => $assignee,
        ]));
        return response()->json(['data' => $this->serialize($t->fresh(['creator', 'assignee']), $u)], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $u = $request->user();
        $this->authorizeAccess($request, $task);
        $wasCompleted = $task->completed;
        $v = $this->validatePayload($request, false);

        // Delegar (mudar responsável p/ outra pessoa) exige permissão.
        if (array_key_exists('assigned_to', $v) && (int) $v['assigned_to'] !== $u->id && !$this->canDelegate($u)) {
            abort(403, 'Você não tem permissão para delegar tarefas.');
        }

        // Concluir/reabrir: qualquer RESPONSÁVEL (principal ou um dos múltiplos) — conclui p/ todos.
        $togglingComplete = array_key_exists('completed', $v) && (bool) $v['completed'] !== $wasCompleted;
        if ($togglingComplete) {
            abort_unless(in_array($u->id, $task->allAssigneeIds(), true), 403, 'Apenas um responsável pode concluir/reabrir esta tarefa.');
        }

        $task->update($v);

        if (!$wasCompleted && $task->completed) {
            $task->forceFill(['completed_at' => now(), 'completed_by' => $u->id])->save();
            $this->spawnNextOccurrence($task);
            $this->notifyCreator($task, $u);     // avisa o criador (se for outro)
        } elseif ($wasCompleted && !$task->completed) {
            $task->forceFill(['completed_at' => null, 'completed_by' => null])->save();
        }

        return response()->json(['data' => $this->serialize($task->fresh(['creator', 'assignee', 'completer']), $u)]);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorizeAccess($request, $task);
        $task->delete();
        return response()->json(null, 204);
    }

    /**
     * Visão de EQUIPE (Coordenador/Admin): tarefas pendentes da equipe + roster de membros.
     * Coordenador → consultores alocados nos projetos que coordena (+ subprojetos).
     * Coordenador de sustentação / admin / administrativo → todos os internos ativos.
     */
    public function team(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u && $this->canDelegate($u), 403);

        // Roster da equipe (exclui o próprio — a visão dele é "Minhas tarefas").
        $memberIds = array_values(array_diff($this->resolveTeamMemberIds($u), [$u->id]));

        $members = empty($memberIds)
            ? collect()
            : \App\Models\User::whereIn('id', $memberIds)->orderBy('name')->get(['id', 'name']);

        $tasks = collect();
        if (!empty($memberIds)) {
            $tasks = Task::query()
                ->with(['creator:id,name', 'assignee:id,name', 'completer:id,name'])
                ->whereIn('assigned_to', $memberIds)
                ->where('completed', false)
                ->orderByRaw('due_date is null')
                ->orderBy('due_date')
                ->orderByRaw('due_time is null')
                ->orderBy('due_time')
                ->limit(500)->get();
        }

        return response()->json(['data' => [
            'members' => $members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->all(),
            'tasks'   => $tasks->map(fn (Task $t) => $this->serialize($t, $u))->all(),
        ]]);
    }

    /**
     * Coordenador/Admin marca como RESOLVIDA uma tarefa de um membro da sua equipe.
     * Endpoint dedicado e limitado (não abre edição/exclusão de tarefa alheia).
     */
    public function resolve(Request $request, Task $task): JsonResponse
    {
        $u = $request->user();
        abort_unless($u && $this->canDelegate($u), 403);
        $memberIds = $this->resolveTeamMemberIds($u);
        abort_unless(in_array($task->assigned_to, $memberIds, true), 403, 'Tarefa fora da sua equipe.');

        if (!$task->completed) {
            $task->forceFill(['completed' => true, 'completed_at' => now(), 'completed_by' => $u->id])->save();
            $this->spawnNextOccurrence($task);
            if ($task->assigned_to && $task->assigned_to !== $u->id) {
                \App\Models\AppNotification::create([
                    'title'        => 'Tarefa resolvida pela coordenação',
                    'message'      => e($u->name) . ' marcou como resolvida a tarefa: <b>' . e($task->title) . '</b>.',
                    'type'         => 'info',
                    'priority'     => 'medium',
                    'target_users' => [$task->assigned_to],
                    'send_email'   => false,
                    'visible'      => true,
                    'created_by'   => $u->id,
                    'expires_at'   => now()->addDays(7),
                ]);
            }
        }

        return response()->json(['data' => $this->serialize($task->fresh(['creator', 'assignee', 'completer']), $u)]);
    }

    /** IDs dos membros da equipe do coordenador/admin. */
    private function resolveTeamMemberIds(\App\Models\User $u): array
    {
        // Admin / administrativo → todos os internos ativos.
        if (in_array($u->type, ['admin', 'administrativo'], true)) {
            return \App\Models\User::where('enabled', true)
                ->whereIn('type', ['consultor', 'coordenador', 'administrativo'])
                ->pluck('id')->all();
        }

        // Coordenador de sustentação → todos os consultores/coordenadores ativos.
        if ($u->coordinator_type === 'sustentacao') {
            return \App\Models\User::where('enabled', true)
                ->whereIn('type', ['consultor', 'coordenador'])
                ->pluck('id')->all();
        }

        // Coordenador de projetos → consultores alocados nos projetos que coordena (+ subprojetos).
        $projectIds = \DB::table('project_coordinators')->where('user_id', $u->id)->pluck('project_id');
        if ($projectIds->isEmpty()) return [];
        $childIds = \App\Models\Project::whereIn('parent_project_id', $projectIds)->pluck('id');
        $allProjectIds = $projectIds->merge($childIds)->unique()->values();

        return \DB::table('project_consultants')
            ->whereIn('project_id', $allProjectIds)
            ->pluck('user_id')->unique()->values()->all();
    }

    /** Só admin/coordenador/administrativo podem delegar tarefas a outros. */
    private function canDelegate(\App\Models\User $u): bool
    {
        return in_array($u->type, ['admin', 'coordenador', 'administrativo'], true);
    }

    /** Busca de usuários internos p/ delegar (responsável). Só p/ quem pode delegar. */
    public function users(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u && $this->canDelegate($u), 403);
        $q = trim((string) $request->query('search', ''));
        $rows = \App\Models\User::query()->where('enabled', true)->where('type', '!=', 'cliente')
            ->when($q !== '', fn ($w) => $w->where('name', 'ilike', "%{$q}%"))
            ->orderBy('name')->limit(20)->get(['id', 'name']);
        return response()->json(['data' => $rows]);
    }

    /** Busca de entidades p/ vínculo (cliente / projeto / contrato). */
    public function entities(Request $request): JsonResponse
    {
        abort_unless($request->user(), 401);
        $type = (string) $request->query('type');
        $q = trim((string) $request->query('search', ''));
        abort_unless(in_array($type, Task::ENTITY_TYPES, true), 422, 'Tipo de vínculo inválido.');

        [$model, $col] = match ($type) {
            'customer' => [\App\Models\Customer::class, 'name'],
            'project'  => [\App\Models\Project::class, 'name'],
            'contract' => [\App\Models\Contract::class, 'project_name'],
        };

        $customerId = $request->query('customer_id');

        $rows = $model::query()
            ->when($q !== '', fn ($w) => $w->where($col, 'ilike', "%{$q}%"))
            ->when($type === 'project' && $customerId, fn ($w) => $w->where('customer_id', $customerId))
            ->orderBy($col)->limit(50)->get(['id', $col . ' as name']);

        return response()->json(['data' => $rows]);
    }

    /** Recorrência: ao concluir, gera a PRÓXIMA ocorrência (mantém a concluída no histórico). */
    private function spawnNextOccurrence(Task $task): void
    {
        if ($task->recurrence_type === 'none') return;
        $next = $task->nextDueDate();
        if (!$next) return;
        if ($task->recurrence_end_date && $next->gt($task->recurrence_end_date)) return; // acabou a série

        Task::create([
            'user_id'             => $task->user_id,
            'created_by'          => $task->created_by,
            'assigned_to'         => $task->assigned_to,
            'title'               => $task->title,
            'description'         => $task->description,
            'due_date'            => $next->toDateString(),
            'due_time'            => $task->due_time,
            'completed'           => false,
            'type'                => $task->type,
            'entity_type'         => $task->entity_type,
            'entity_id'           => $task->entity_id,
            'recurrence_type'     => $task->recurrence_type,
            'recurrence_interval' => $task->recurrence_interval,
            'recurrence_weekdays' => $task->recurrence_weekdays,
            'recurrence_end_date' => $task->recurrence_end_date?->toDateString(),
        ]);
    }

    /** Avisa o CRIADOR quando a tarefa delegada é concluída por outra pessoa (notificação in-app). */
    private function notifyCreator(Task $task, \App\Models\User $completer): void
    {
        if (!$task->created_by || $task->created_by === $completer->id) return;
        \App\Models\AppNotification::create([
            'title'        => 'Tarefa concluída',
            'message'      => e($completer->name) . ' concluiu a tarefa: <b>' . e($task->title) . '</b>.',
            'type'         => 'info',
            'priority'     => 'medium',
            'target_users' => [$task->created_by],
            'send_email'   => false,
            'visible'      => true,
            'created_by'   => $completer->id,
            'expires_at'   => now()->addDays(7),
        ]);
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'title'               => ($creating ? 'required' : 'sometimes') . '|string|max:500',
            'description'         => 'nullable|string',
            'assigned_to'         => 'nullable|integer|exists:users,id',
            'due_date'            => 'nullable|date',
            'due_time'            => 'nullable|date_format:H:i',
            'completed'           => 'nullable|boolean',
            'type'                => 'nullable|in:' . implode(',', Task::TYPES),
            'priority'            => 'nullable|in:' . implode(',', Task::PRIORITIES),
            'entity_type'         => 'nullable|in:' . implode(',', Task::ENTITY_TYPES),
            'entity_id'           => 'nullable|integer',
            'recurrence_type'     => 'nullable|in:' . implode(',', Task::RECURRENCES),
            'recurrence_interval' => 'nullable|integer|min:1|max:99',
            'recurrence_weekdays'   => 'nullable|array',
            'recurrence_weekdays.*' => 'integer|min:0|max:6',
            'recurrence_end_date' => 'nullable|date',
        ]);
    }

    private function authorizeAccess(Request $request, Task $task): void
    {
        $u = $request->user();
        // Criador, responsável principal OU um dos múltiplos responsáveis (pivot).
        abort_unless($u && ($task->created_by === $u->id || in_array($u->id, $task->allAssigneeIds(), true)), 403);
    }

    private function serialize(Task $t, ?\App\Models\User $u = null): array
    {
        return [
            'id'                  => $t->id,
            'title'               => $t->title,
            'description'         => $t->description,
            'due_date'            => $t->due_date?->toDateString(),
            'due_time'            => $t->due_time ? substr((string) $t->due_time, 0, 5) : null,
            'completed'           => $t->completed,
            'type'                => $t->type,
            'priority'            => $t->priority,
            'entity_type'         => $t->entity_type,
            'entity_id'           => $t->entity_id,
            'entity_label'        => $this->entityLabel($t),
            'recurrence_type'     => $t->recurrence_type,
            'recurrence_interval' => $t->recurrence_interval,
            'recurrence_weekdays' => $t->recurrence_weekdays ?? [],
            'recurrence_end_date' => $t->recurrence_end_date?->toDateString(),
            // Delegação
            'created_by'     => $t->created_by,
            'created_name'   => $t->creator?->name,
            'assigned_to'    => $t->assigned_to,
            'assigned_name'  => $t->assignee?->name,
            'completed_by'   => $t->completed_by,
            'completed_name' => $t->completer?->name,
            'completed_at'   => $t->completed_at?->toIso8601String(),
            'is_creator'     => $u ? $t->created_by === $u->id : null,
            'is_assignee'    => $u ? $t->assigned_to === $u->id : null,
            'delegated'      => $t->created_by !== $t->assigned_to,
        ];
    }

    private function entityLabel(Task $t): ?string
    {
        if (!$t->entity_type || !$t->entity_id) return null;
        return match ($t->entity_type) {
            'customer' => \App\Models\Customer::find($t->entity_id)?->name,
            'project'  => \App\Models\Project::find($t->entity_id)?->name,
            'contract' => \App\Models\Contract::find($t->entity_id)?->project_name,
            'meeting'  => \App\Models\Meeting::find($t->entity_id)?->title,
            default    => null,
        };
    }
}
