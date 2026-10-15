<?php

namespace App\Http\Controllers;

use App\Meetings\MeetingOriginRegistry;
use App\Meetings\MeetingService;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reunião — DOIS contratos convivem no mesmo /meetings:
 *  - ANTIGO (Central de Reunião, admin+coordenador): meeting_date + tarefas; visível só aos
 *    envolvidos (participantes) + criador; admin vê tudo.
 *  - NOVO (Central de Reuniões / Teams a partir do chamado): starts_at + provider + origem
 *    polimórfica; delega a regra ao MeetingService. Distinção pelo payload/param (starts_at /
 *    origin_type). As reuniões novas têm origin_type preenchido; as antigas, NULL.
 */
class MeetingController extends Controller
{
    private const MANAGERS = ['admin', 'coordenador'];

    public function __construct(private MeetingService $svc)
    {
    }

    /** Reunião do modelo NOVO por id (sem o gate de manager da Central antiga). */
    private function findNew(int $id): Meeting
    {
        return Meeting::findOrFail($id);
    }

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
        // NOVO: reunião vinculada a uma ORIGEM (chamado/projeto/...) — contrato novo, sem gate de manager.
        if ($request->filled('origin_type')) {
            return $this->indexNew($request);
        }

        // ANTIGO (Central de Reunião): só reuniões antigas (origin_type NULL) — as novas não aparecem aqui.
        $u = $this->manager($request);
        $rows = Meeting::visibleTo($u)
            ->whereNull('origin_type')
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
                'tasks_count' => Task::withoutCompanyScope()->where('entity_type', 'meeting')->where('entity_id', $m->id)->count(),
                'open_tasks_count' => Task::withoutCompanyScope()->where('entity_type', 'meeting')->where('entity_id', $m->id)->where('completed', false)->count(),
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
        // NOVO: agendamento com starts_at/provider/origem (modal do chamado) → MeetingService.
        if ($request->filled('starts_at')) {
            return $this->storeNew($request);
        }

        $u = $this->manager($request);
        $v = $request->validate([
            'title'            => 'required|string|max:250',
            'meeting_date'     => 'required|date',
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
        // NOVO: reagendar (starts_at/duration) — modal do chamado.
        if ($request->filled('starts_at')) {
            return $this->rescheduleNew($request, $meeting);
        }

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
        return response()->json(['data' => $this->serializeTask(Task::withoutCompanyScope()->with(['assignee', 'creator', 'assignees:id,name'])->find($t->id))], 201);
    }

    /** Editar tarefa da reunião (título/responsáveis/prazo) — criador ou admin. */
    public function updateTask(Request $request, int $meeting, int $task): JsonResponse
    {
        $u = $this->manager($request);
        $m = $this->findVisible($u, $meeting);
        $t = Task::withoutCompanyScope()->where('entity_type', 'meeting')->where('entity_id', $m->id)->findOrFail($task);
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
        return response()->json(['data' => $this->serializeTask(Task::withoutCompanyScope()->with(['assignee', 'creator', 'assignees:id,name'])->find($t->id))]);
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
        $t = Task::withoutCompanyScope()->where('entity_type', 'meeting')->where('entity_id', $m->id)->findOrFail($task);
        abort_unless($u->isAdmin() || $t->created_by === $u->id, 403, 'Apenas quem criou a tarefa (ou admin) pode removê-la.');
        $t->delete();
        return response()->json(['ok' => true]);
    }

    /** Concluir/reabrir tarefa da reunião — QUALQUER responsável (conclui p/ todos) ou admin. */
    public function toggleTask(Request $request, int $meeting, int $task): JsonResponse
    {
        $u = $this->manager($request);
        $m = $this->findVisible($u, $meeting);
        $t = Task::withoutCompanyScope()->where('entity_type', 'meeting')->where('entity_id', $m->id)->with('assignees:id')->findOrFail($task);
        abort_unless($u->isAdmin() || in_array($u->id, $t->allAssigneeIds(), true), 403, 'Apenas um responsável pode concluir/reabrir.');
        $done = !$t->completed;
        // Tarefa é ÚNICA: concluir marca a task inteira → conclui p/ TODOS os responsáveis.
        $t->update(['completed' => $done, 'completed_at' => $done ? now() : null, 'completed_by' => $done ? $u->id : null]);
        if ($done) self::notifyTaskCompletion($t, $u);   // avisa os envolvidos da reunião
        return response()->json(['data' => $this->serializeTask(Task::withoutCompanyScope()->with(['assignee', 'creator', 'assignees:id,name', 'completer'])->find($t->id))]);
    }

    /**
     * Pop-up para TODOS os envolvidos da reunião quando uma tarefa dela é concluída
     * (via Central OU via Meu Dia). Notifica participantes + criador, exceto quem concluiu.
     * Reusado pelo TaskController (conclusão pelo Meu Dia/Minhas Tarefas).
     */
    public static function notifyTaskCompletion(Task $t, User $completer): void
    {
        if ($t->entity_type !== 'meeting' || !$t->entity_id) return;
        $m = Meeting::with('participants:id')->find($t->entity_id);
        if (!$m) return;

        $targets = $m->participants->pluck('id')
            ->push($m->created_by_id)
            ->filter()
            ->reject(fn ($id) => (int) $id === (int) $completer->id)   // não avisa quem concluiu
            ->unique()->values()->all();
        if (empty($targets)) return;

        $quando = $m->meeting_date ? ' (' . $m->meeting_date->format('d/m/Y H:i') . ')' : '';
        \App\Models\AppNotification::create([
            'title'        => 'Tarefa de reunião concluída',
            'message'      => e($completer->name) . ' concluiu a tarefa <b>' . e($t->title) . '</b>'
                              . ' referente à reunião <b>' . e($m->title) . '</b>' . e($quando) . '.',
            'type'         => 'info',
            'priority'     => 'medium',
            'target_users' => $targets,
            'send_email'   => false,
            'visible'      => true,
            'created_by'   => $completer->id,
            'expires_at'   => now()->addDays(7),
        ]);
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

        $q = Task::withoutCompanyScope()->where('entity_type', 'meeting')
            ->whereIn('entity_id', $meetingIds)
            ->with(['assignees:id,name', 'assignee:id,name', 'completer:id,name']);
        if ($request->filled('meeting_id')) {
            $q->where('entity_id', (int) $request->query('meeting_id'));
        }
        // Filtro por MÊS (YYYY-MM) da reunião — meeting_date é wall-clock gravado em UTC.
        if ($request->filled('month')) {
            $monthIds = Meeting::visibleTo($u)
                ->whereNotNull('meeting_date')
                ->whereRaw("to_char(meeting_date, 'YYYY-MM') = ?", [$request->query('month')])
                ->pluck('id');
            $q->whereIn('entity_id', $monthIds);
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
                'completed_by_name' => $t->completer?->name,
                'completed_at'  => optional($t->completed_at)->toIso8601String(),
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

        // Reuniões (p/ o filtro do FE) — com data p/ desambiguar nomes iguais + derivar meses.
        $meetingOptions = Meeting::visibleTo($u)
            ->orderByRaw('meeting_date is null')->orderByDesc('meeting_date')->orderByDesc('id')
            ->get(['id', 'title', 'meeting_date'])
            ->map(fn ($m) => [
                'id'           => $m->id,
                'title'        => $m->title,
                'meeting_date' => optional($m->meeting_date)->toIso8601String(),
                'month'        => optional($m->meeting_date)->format('Y-m'),
            ])->values();

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
        $tasks = Task::withoutCompanyScope()->where('entity_type', 'meeting')->where('entity_id', $m->id)
            ->with(['assignee:id,name', 'creator:id,name', 'assignees:id,name', 'completer:id,name'])->orderBy('completed')->orderByRaw('due_date is null')->orderBy('due_date')->get();
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
            'completed_by_name' => $t->relationLoaded('completer') ? $t->completer?->name : $t->completer()->first()?->name,
            'completed_at' => optional($t->completed_at)->toIso8601String(),
        ];
    }

    // =====================================================================================
    // MODELO NOVO — Central de Reuniões / Teams a partir do chamado (contrato starts_at/origem).
    // =====================================================================================

    private function indexNew(Request $request): JsonResponse
    {
        $q = Meeting::with(['participantRows', 'organizer:id,name'])->orderByDesc('starts_at');

        if ($request->filled('origin_type') && $request->filled('origin_id')) {
            $q->where('origin_type', $request->origin_type)->where('origin_id', (int) $request->origin_id);
        }
        if ($request->filled('status'))   $q->where('status', $request->status);
        if ($request->filled('provider')) $q->where('provider', $request->provider);
        if ($request->filled('from'))     $q->where('starts_at', '>=', $request->from);
        if ($request->filled('to'))       $q->where('starts_at', '<=', $request->to);
        if ($request->filled('search'))   $q->where('title', 'ilike', '%' . $request->search . '%');

        $meetings = $q->limit(300)->get();
        $refs = $this->resolveOriginRefs($meetings);
        return response()->json(['data' => $meetings->map(fn ($m) => $this->decorate($m) + [
            'origin_ref' => $refs["{$m->origin_type}:{$m->origin_id}"] ?? null,
        ])]);
    }

    private function storeNew(Request $request): JsonResponse
    {
        $v = $request->validate([
            'title'                              => 'required|string|max:200',
            'description'                        => 'nullable|string',
            'provider'                           => 'nullable|in:teams,meet,zoom,webex,presencial',
            'starts_at'                          => 'required|date',
            'duration_minutes'                   => 'nullable|integer|min:5|max:1440',
            'timezone'                           => 'nullable|string|max:40',
            'origin_type'                        => 'nullable|string|max:40',
            'origin_id'                          => 'nullable|integer',
            'organizer_user_id'                  => 'nullable|integer|exists:users,id',
            'participants'                       => 'nullable|array',
            'participants.*.user_id'             => 'nullable|integer',
            'participants.*.customer_contact_id' => 'nullable|integer',
            'participants.*.email'               => 'nullable|email',
            'participants.*.name'                => 'nullable|string|max:190',
            'participants.*.role'                => 'nullable|string|max:20',
            'send_invites'                       => 'nullable|boolean',
        ]);
        if (!empty($v['origin_type'])) {
            abort_unless(MeetingOriginRegistry::isValid($v['origin_type']), 422, 'Origem de reunião inválida.');
        }
        $meeting = $this->svc->create($v, $request->user());
        return response()->json(['data' => $this->decorate($meeting, true)], 201);
    }

    private function rescheduleNew(Request $request, int $meeting): JsonResponse
    {
        $m = $this->findNew($meeting);
        $v = $request->validate(['starts_at' => 'required|date', 'duration_minutes' => 'nullable|integer|min:5|max:1440']);
        $this->svc->reschedule($m, $v['starts_at'], $v['duration_minutes'] ?? null);
        return response()->json(['data' => $this->decorate($m->fresh(['participantRows']), true)]);
    }

    public function cancel(Request $request, int $meeting): JsonResponse
    {
        $m = $this->findNew($meeting);
        $this->svc->cancel($m, $request->input('reason'));
        return response()->json(['data' => $this->decorate($m->fresh(['participantRows']), true)]);
    }

    public function start(Request $request, int $meeting): JsonResponse
    {
        $m = $this->findNew($meeting);
        $this->svc->start($m);
        return response()->json(['data' => $this->decorate($m->fresh(['participantRows']), true)]);
    }

    public function end(Request $request, int $meeting): JsonResponse
    {
        $m = $this->findNew($meeting);
        $this->svc->end($m);
        return response()->json(['data' => $this->decorate($m->fresh(['participantRows']), true)]);
    }

    /**
     * Apontar as horas da reunião — REUSA TimesheetController::store (mesmo endpoint, zero lógica
     * paralela). Resolve o projeto pela origem (projeto direto, ou o projeto do chamado).
     */
    public function logHours(Request $request, int $meeting): JsonResponse
    {
        $m = $this->findNew($meeting);
        $v = $request->validate([
            'project_id'     => 'nullable|integer|exists:projects,id',
            'effort_minutes' => 'nullable|integer|min:1|max:1440',
            'description'    => 'nullable|string|max:5000',
        ]);

        $projectId = $v['project_id'] ?? $this->resolveProjectForMeeting($m);
        abort_unless($projectId, 422, 'Não foi possível determinar o projeto — informe o projeto para apontar as horas.');

        $minutes  = $v['effort_minutes'] ?? $m->duration_minutes ?? 30;
        $ticketId = $m->origin_type === 'HELPDESK_TICKET' ? $m->origin_id : null;

        $tsReq = Request::create('/timesheets', 'POST', [
            'project_id'         => $projectId,
            'date'               => optional($m->starts_at)->toDateString(),
            'total_hours'        => sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60),
            'observation'        => $v['description'] ?? ('Reunião: ' . $m->title),
            'helpdesk_ticket_id' => $ticketId,
        ]);
        $tsReq->setUserResolver(fn () => $request->user());

        $resp = app(\App\Http\Controllers\TimesheetController::class)->store($tsReq);
        if ($resp->getStatusCode() >= 400) return $resp;

        $tsId = data_get($resp->getData(true), 'data.id') ?? data_get($resp->getData(true), 'id');
        \App\Models\MeetingEvent::log($m->id, 'hours_logged', ['meta' => ['minutes' => $minutes, 'timesheet_id' => $tsId, 'project_id' => $projectId]]);

        return response()->json(['data' => ['ok' => true, 'timesheet_id' => $tsId, 'minutes' => $minutes]], 201);
    }

    public function saveSummary(Request $request, int $meeting): JsonResponse
    {
        $m = $this->findNew($meeting);
        $v = $request->validate(['summary' => 'required|string|max:20000']);
        $m->update(['summary' => $v['summary']]);
        \App\Models\MeetingEvent::log($m->id, 'summary_ready', []);
        return response()->json(['data' => ['ok' => true]]);
    }

    public function saveAta(Request $request, int $meeting): JsonResponse
    {
        $m = $this->findNew($meeting);
        $v = $request->validate(['ata' => 'required|string|max:50000']);
        $m->update(['ata' => $v['ata']]);
        \App\Models\MeetingEvent::log($m->id, 'ata_generated', []);
        return response()->json(['data' => ['ok' => true]]);
    }

    /** Gera RESUMO + ATA por IA a partir da transcrição (do corpo ou já salva na reunião). */
    public function generateNotes(Request $request, int $meeting): JsonResponse
    {
        $m  = $this->findNew($meeting);
        $ai = app(\App\Services\MeetingAiService::class);
        abort_unless($ai->isConfigured(), 422, 'Geração por IA não está configurada (ANTHROPIC_API_KEY ausente).');

        $v = $request->validate(['transcript' => 'nullable|string|max:200000']);
        $transcript = trim((string) ($v['transcript'] ?? $m->transcript ?? ''));
        abort_if($transcript === '', 422, 'Sem transcrição para gerar o resumo. Cole a transcrição da reunião.');

        if ($transcript !== trim((string) $m->transcript)) {
            $m->transcript = $transcript;
        }

        $out = $ai->generate($transcript, $m->title);
        abort_if($out === null, 502, 'A IA não conseguiu gerar o resumo agora. Tente novamente ou preencha manualmente.');

        $m->summary = $out['summary'] ?: $m->summary;
        $m->ata     = $out['ata'] ?: $m->ata;
        $m->save();

        \App\Models\MeetingEvent::log($m->id, 'summary_ready', ['meta' => ['source' => 'ai']]);
        if ($out['ata']) {
            \App\Models\MeetingEvent::log($m->id, 'ata_generated', ['meta' => ['source' => 'ai']]);
        }

        return response()->json(['data' => ['summary' => $m->summary, 'ata' => $m->ata]]);
    }

    /** Registra uma nota interna no ticket de origem resumindo a reunião. */
    public function noteToTicket(Request $request, int $meeting): JsonResponse
    {
        $m = $this->findNew($meeting);
        abort_unless($m->origin_type === 'HELPDESK_TICKET' && $m->origin_id, 422, 'Reunião não está vinculada a um chamado.');
        $ticket = \App\Models\HelpDeskTicket::findOrFail($m->origin_id);

        $parts = $m->participantRows->map(fn ($p) => $p->name ?: $p->email)->filter()->implode(', ');
        $body = '<p><strong>📅 Reunião realizada</strong> — ' . e($m->title) . '<br>'
            . e(optional($m->starts_at)->format('d/m/Y H:i')) . ($m->duration_minutes ? ' · ' . $m->duration_minutes . ' min' : '')
            . ($parts ? '<br>Participantes: ' . e($parts) : '') . '</p>'
            . ($m->summary ? '<hr>' . nl2br(e($m->summary)) : '');

        $comment = $ticket->comments()->create([
            'author_user_id' => $request->user()?->id,
            'body'           => $body,
            'visibility'     => 'internal',
            'channel'        => 'interno',
        ]);
        \App\Models\HelpDeskTicketEvent::log($ticket->id, 'note', ['to_value' => 'Reunião registrada', 'meta' => ['meeting_id' => $m->id, 'comment_id' => $comment->id]]);

        return response()->json(['data' => ['ok' => true, 'comment_id' => $comment->id]], 201);
    }

    /**
     * PORTAL do cliente — reuniões do cliente logado (origem = cliente, ou chamado do cliente, ou o
     * próprio contato como participante). Payload LEVE (sem detalhes internos).
     */
    public function portalMeetings(Request $request): JsonResponse
    {
        $cid = $request->user()?->customer_id;
        abort_unless($cid, 403, 'Usuário não vinculado a um cliente.');

        $contactIds = \App\Models\CustomerContact::where('customer_id', $cid)->pluck('id');
        $ticketIds  = \App\Models\HelpDeskTicket::where('customer_id', $cid)->pluck('id');

        $meetings = Meeting::with('participantRows')->whereNotNull('origin_type')->where(function ($w) use ($cid, $ticketIds, $contactIds) {
            $w->where(fn ($x) => $x->where('origin_type', 'CUSTOMER')->where('origin_id', $cid))
              ->orWhere(fn ($x) => $x->where('origin_type', 'HELPDESK_TICKET')->whereIn('origin_id', $ticketIds))
              ->orWhereHas('participantRows', fn ($p) => $p->whereIn('customer_contact_id', $contactIds));
        })->orderByDesc('starts_at')->limit(100)->get();

        return response()->json(['data' => $meetings->map(fn ($m) => [
            'id'        => $m->id,
            'title'     => $m->title,
            'provider'  => $m->provider,
            'status'    => $m->status,
            'starts_at' => optional($m->starts_at)->toIso8601String(),
            'ends_at'   => optional($m->ends_at)->toIso8601String(),
            'duration_minutes' => $m->duration_minutes,
            'join_url'  => $m->join_url,
        ])]);
    }

    /** Participantes SUGERIDOS a partir da origem (ex.: chamado → solicitante + responsável + CC). */
    public function suggestedParticipants(Request $request): JsonResponse
    {
        $type = $request->query('origin_type');
        $id   = (int) $request->query('origin_id');
        abort_unless(MeetingOriginRegistry::isValid($type) && $id, 422, 'Origem inválida.');

        $out = [];
        if ($type === 'HELPDESK_TICKET') {
            $t = \App\Models\HelpDeskTicket::with(['contact:id,name,email', 'requester:id,name,email', 'assignee:id,name,email'])->find($id);
            if ($t) {
                if ($t->contact) $out[] = ['customer_contact_id' => $t->contact->id, 'name' => $t->contact->name, 'email' => $t->contact->email, 'role' => 'solicitante', 'is_external' => true];
                elseif ($t->requester_email) $out[] = ['email' => $t->requester_email, 'name' => $t->requester_name, 'role' => 'solicitante', 'is_external' => true];
                if ($t->assignee) $out[] = ['user_id' => $t->assignee->id, 'name' => $t->assignee->name, 'email' => $t->assignee->email, 'role' => 'responsavel'];
                foreach ((array) $t->cc_emails as $cc) {
                    if ($cc) $out[] = ['email' => $cc, 'role' => 'optional', 'is_external' => true];
                }
            }
        }
        return response()->json(['data' => $out]);
    }

    /** Resolve a referência humana da origem em lote (anti-N+1): chamado→número, projeto/cliente→nome. */
    private function resolveOriginRefs(\Illuminate\Support\Collection $meetings): array
    {
        $out = [];
        $byType = $meetings->whereNotNull('origin_type')->groupBy('origin_type');
        foreach ($byType as $type => $set) {
            $ids = $set->pluck('origin_id')->filter()->unique()->all();
            if (!$ids) continue;
            $map = match ($type) {
                'HELPDESK_TICKET' => \App\Models\HelpDeskTicket::whereIn('id', $ids)->pluck('ticket_number', 'id'),
                'PROJECT'         => \App\Models\Project::whereIn('id', $ids)->pluck('name', 'id'),
                'CUSTOMER'        => \App\Models\Customer::whereIn('id', $ids)->pluck('name', 'id'),
                'CONTRACT'        => \App\Models\Contract::whereIn('id', $ids)->pluck('id', 'id'),
                default           => collect(),
            };
            foreach ($map as $id => $ref) $out["{$type}:{$id}"] = (string) $ref;
        }
        return $out;
    }

    /** Projeto p/ o apontamento: origem projeto → ela mesma; origem chamado → o projeto do chamado. */
    private function resolveProjectForMeeting(Meeting $meeting): ?int
    {
        if ($meeting->origin_type === 'PROJECT') return $meeting->origin_id;
        if ($meeting->origin_type === 'HELPDESK_TICKET') {
            $t = \App\Models\HelpDeskTicket::find($meeting->origin_id);
            if ($t?->project_id) return $t->project_id;
            if ($t?->contract_id) return optional(\App\Models\Contract::find($t->contract_id))->project_id;
        }
        return null;
    }

    private function decorate(Meeting $m, bool $withEvents = false): array
    {
        $data = [
            'id'                  => $m->id,
            'title'               => $m->title,
            'description'         => $m->description,
            'provider'            => $m->provider,
            'status'              => $m->status,
            'starts_at'           => optional($m->starts_at)->toIso8601String(),
            'ends_at'             => optional($m->ends_at)->toIso8601String(),
            'duration_minutes'    => $m->duration_minutes,
            'join_url'            => $m->join_url,
            'external_meeting_id' => $m->external_meeting_id,
            'provider_data'       => $m->provider_data,
            'organizer'           => $m->organizer ? ['id' => $m->organizer->id, 'name' => $m->organizer->name] : null,
            'origin_type'         => $m->origin_type,
            'origin_label'        => MeetingOriginRegistry::label($m->origin_type),
            'origin_id'           => $m->origin_id,
            'is_upcoming'         => $m->isUpcoming(),
            'is_past'             => $m->isPast(),
            'started_at'          => optional($m->started_at)->toIso8601String(),
            'ended_at'            => optional($m->ended_at)->toIso8601String(),
            'summary'             => $m->summary,
            'ata'                 => $m->ata,
            'transcript'          => $m->transcript,
            'ai_enabled'          => (bool) config('services.anthropic.api_key'),
            'participants'        => $m->relationLoaded('participantRows') ? $m->participantRows->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name ?: optional($p->user)->name ?: optional($p->contact)->name,
                'email' => $p->email, 'role' => $p->role, 'response' => $p->response,
                'is_external' => (bool) $p->is_external, 'user_id' => $p->user_id, 'customer_contact_id' => $p->customer_contact_id,
            ])->values() : [],
        ];
        if ($withEvents) {
            $data['events'] = $m->events()->orderBy('created_at')->orderBy('id')->get(['event_type', 'meta', 'created_at'])
                ->map(fn ($e) => ['type' => $e->event_type, 'meta' => $e->meta, 'created_at' => optional($e->created_at)->toIso8601String()]);
        }
        return $data;
    }
}
