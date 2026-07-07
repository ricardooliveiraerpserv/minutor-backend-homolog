<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskGroup;
use App\Services\RoutineGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rotinas de Equipe (task_groups). Quem gerencia = admin/coordenador/administrativo. */
class TaskGroupController extends Controller
{
    private const MANAGERS = ['admin', 'coordenador', 'administrativo'];

    private function authorizeManager(Request $request): \App\Models\User
    {
        $u = $request->user();
        abort_unless($u && in_array($u->type, self::MANAGERS, true), 403, 'Sem permissão para gerenciar rotinas.');
        return $u;
    }

    /** Lista rotinas (admin vê todas; demais gestores veem as próprias). */
    public function index(Request $request): JsonResponse
    {
        $u = $this->authorizeManager($request);
        $rows = TaskGroup::with(['owner:id,name', 'users:id,name', 'items'])
            ->when(!$u->isAdmin(), fn ($q) => $q->where('owner_id', $u->id))
            ->orderByDesc('id')->get()
            ->map(fn (TaskGroup $g) => $this->serialize($g));
        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->authorizeManager($request);
        $v = $this->validatePayload($request);
        $g = TaskGroup::create(['nome' => $v['nome'], 'descricao' => $v['descricao'] ?? null, 'owner_id' => $u->id, 'active' => $v['active'] ?? true, 'start_date' => $v['start_date'] ?? null, 'end_date' => $v['end_date'] ?? null]);
        $this->syncUsersAndItems($g, $v);
        return response()->json(['data' => $this->serialize($g->fresh(['owner', 'users', 'items']))], 201);
    }

    public function update(Request $request, TaskGroup $taskGroup): JsonResponse
    {
        $this->authorizeOwner($request, $taskGroup);
        $v = $this->validatePayload($request);
        $taskGroup->update(['nome' => $v['nome'], 'descricao' => $v['descricao'] ?? null, 'active' => $v['active'] ?? $taskGroup->active, 'start_date' => $v['start_date'] ?? null, 'end_date' => $v['end_date'] ?? null]);
        $this->syncUsersAndItems($taskGroup, $v);
        return response()->json(['data' => $this->serialize($taskGroup->fresh(['owner', 'users', 'items']))]);
    }

    public function destroy(Request $request, TaskGroup $taskGroup): JsonResponse
    {
        $this->authorizeOwner($request, $taskGroup);
        $taskGroup->delete();
        return response()->json(null, 204);
    }

    /** Gera as tasks da rotina AGORA (hoje) — útil p/ testar/disparar manualmente. */
    public function generate(Request $request, TaskGroup $taskGroup, RoutineGenerator $gen): JsonResponse
    {
        $this->authorizeOwner($request, $taskGroup);
        $n = $gen->generateGroup($taskGroup->load(['users:id', 'items']), now()->startOfDay());
        return response()->json(['data' => ['generated' => $n]]);
    }

    /** Acompanhamento: grid por usuário (concluídas/pendentes/status) das tasks de HOJE da rotina. */
    public function tracking(Request $request, TaskGroup $taskGroup): JsonResponse
    {
        $this->authorizeOwner($request, $taskGroup);
        $today = now()->toDateString();
        $itemIds = $taskGroup->items()->pluck('id');

        $rows = $taskGroup->users()->get(['users.id', 'users.name'])->map(function ($user) use ($itemIds, $today) {
            $base = Task::whereIn('group_item_id', $itemIds)->where('assigned_to', $user->id)->whereDate('due_date', $today);
            $done = (clone $base)->where('completed', true)->count();
            $pend = (clone $base)->where('completed', false)->count();
            $total = $done + $pend;
            $status = $total === 0 ? 'sem_tarefas' : ($pend === 0 ? 'concluido' : 'pendente');
            return ['user_id' => $user->id, 'user_name' => $user->name, 'concluidas' => $done, 'pendentes' => $pend, 'total' => $total, 'status' => $status];
        });

        return response()->json(['data' => ['users' => $rows, 'date' => $today]]);
    }

    private function syncUsersAndItems(TaskGroup $g, array $v): void
    {
        $g->users()->sync($v['user_ids'] ?? []);
        $g->items()->delete();
        foreach (($v['items'] ?? []) as $it) {
            if (empty(trim((string) ($it['titulo'] ?? '')))) continue;
            $g->items()->create([
                'titulo'              => trim($it['titulo']),
                'tipo'                => $it['tipo'] ?? 'interno',
                'priority'            => $it['priority'] ?? 'media',
                'recorrencia'         => $it['recorrencia'] ?? 'daily',
                'recurrence_weekdays' => $it['recurrence_weekdays'] ?? [],
                'hora_padrao'         => !empty($it['hora_padrao']) ? $it['hora_padrao'] : null,
            ]);
        }
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'nome'                       => 'required|string|max:200',
            'descricao'                  => 'nullable|string',
            'active'                     => 'nullable|boolean',
            'start_date'                 => 'required|date',
            'end_date'                   => 'nullable|date|after_or_equal:start_date',
            'user_ids'                   => 'nullable|array',
            'user_ids.*'                 => 'integer|exists:users,id',
            'items'                      => 'nullable|array',
            'items.*.titulo'             => 'nullable|string|max:500',
            'items.*.tipo'               => 'nullable|in:pessoal,cliente,follow-up,interno',
            'items.*.priority'           => 'nullable|in:baixa,media,alta',
            'items.*.recorrencia'        => 'nullable|in:daily,weekly,monthly',
            'items.*.recurrence_weekdays' => 'nullable|array',
            'items.*.hora_padrao'        => 'nullable|date_format:H:i',
        ]);
    }

    private function authorizeOwner(Request $request, TaskGroup $g): void
    {
        $u = $this->authorizeManager($request);
        abort_unless($u->isAdmin() || $g->owner_id === $u->id, 403);
    }

    private function serialize(TaskGroup $g): array
    {
        return [
            'id'         => $g->id,
            'nome'       => $g->nome,
            'descricao'  => $g->descricao,
            'active'     => $g->active,
            'start_date' => $g->start_date?->format('Y-m-d'),
            'end_date'   => $g->end_date?->format('Y-m-d'),
            'owner_id'   => $g->owner_id,
            'owner_name' => $g->owner?->name,
            'users'      => $g->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'items'      => $g->items->map(fn ($i) => [
                'id' => $i->id, 'titulo' => $i->titulo, 'tipo' => $i->tipo, 'priority' => $i->priority,
                'recorrencia' => $i->recorrencia, 'recurrence_weekdays' => $i->recurrence_weekdays ?? [],
                'hora_padrao' => $i->hora_padrao ? substr((string) $i->hora_padrao, 0, 5) : null,
            ])->values(),
        ];
    }
}
