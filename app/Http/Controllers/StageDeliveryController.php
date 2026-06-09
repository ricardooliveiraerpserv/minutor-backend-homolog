<?php

namespace App\Http\Controllers;

use App\Models\ProjectStage;
use App\Models\StageDelivery;
use App\Services\BusinessCalendarService;
use App\Services\DeliveryApprovalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StageDeliveryController extends Controller
{
    public function index(ProjectStage $stage, Request $request): JsonResponse
    {
        $query = $stage->deliveries()
            ->with('responsible:id,name,email')
            ->withSum('timesheets as effort_minutes_sum', 'effort_minutes');

        // Visão consultor: vê só entregas atribuídas a ele. ADR 0004.
        $user = $request->user();
        if ($user && method_exists($user, 'isConsultor') && $user->isConsultor()) {
            $query->where('responsible_user_id', $user->id);
        }

        $deliveries = $query
            ->orderBy('status')
            ->orderBy('order_index')
            ->get();

        return response()->json(['items' => $deliveries]);
    }

    public function show(StageDelivery $delivery): JsonResponse
    {
        $delivery->load(['responsible:id,name,email', 'stage:id,project_id,name']);

        return response()->json($delivery);
    }

    public function store(Request $request, ProjectStage $stage): JsonResponse
    {
        $data = $request->validate([
            'title'                  => 'required|string|max:200',
            'description'            => 'nullable|string',
            'responsible_user_id'    => 'nullable|exists:users,id',
            'hours_planned'          => 'nullable|numeric|min:0',
            'priority'               => ['nullable', Rule::in(StageDelivery::PRIORITIES)],
            'status'                 => ['nullable', Rule::in(StageDelivery::STATUSES)],
            'due_date'               => 'nullable|date',
            'planned_start_at'       => 'nullable|date',
            'depends_on_delivery_id' => 'nullable|integer|exists:stage_deliveries,id',
            'dependency_type'        => ['nullable', Rule::in(StageDelivery::DEPENDENCY_TYPES)],
            'client_user_id'         => 'nullable|integer|exists:users,id',
            'client_email'           => 'nullable|email|max:180',
            'client_involved'        => 'nullable|boolean',
            'extra_clients'                => 'nullable|array',
            'extra_clients.*.user_id'      => 'nullable|integer|exists:users,id',
            'extra_clients.*.email'        => 'nullable|email|max:180',
            'extra_clients.*.name'         => 'nullable|string|max:180',
        ]);

        // Fim não pode ser anterior ao início.
        if (!empty($data['planned_start_at']) && !empty($data['due_date'])
            && substr((string) $data['due_date'], 0, 10) < substr((string) $data['planned_start_at'], 0, 10)) {
            return response()->json(['message' => 'A data de fim não pode ser anterior ao início.'], 422);
        }

        // Planejamento dentro do pool liberado à gestão (não afrouxa com allow_negative_balance).
        if (($data['hours_planned'] ?? 0) > 0) {
            $err = $this->guardDeliveryPoolCapacity($stage, (float) $data['hours_planned'], (bool) ($data['client_involved'] ?? false), null);
            if ($err !== null) return $err;
        }

        $data['stage_id'] = $stage->id;
        $data['order_index'] = (int) $stage->deliveries()
            ->where('status', $data['status'] ?? StageDelivery::STATUS_BACKLOG)
            ->max('order_index') + 1;

        // Fix Fase 9: auto-persiste due_date sugerida se start+horas presentes e fim ausente.
        // Antes o backend só RETORNAVA suggested_due_date — agora aplica direto pra que
        // duration_business_days não fique null e a UI já mostre o intervalo completo.
        if (empty($data['due_date']) && !empty($data['planned_start_at']) && !empty($data['hours_planned'])) {
            $tmp = new StageDelivery($data);
            if ($suggested = $this->suggestedDueDate($tmp)) {
                $data['due_date'] = $suggested;
            }
        }

        $delivery = StageDelivery::create($data);

        // Prazo de entrega do projeto deriva sempre da última data do cronograma.
        $stage->project?->recalcExpectedEndFromSchedule();

        $payload = $delivery->load('responsible:id,name,email')->toArray();
        if ($suggested = $this->suggestedDueDate($delivery)) {
            $payload['suggested_due_date'] = $suggested;
        }

        return response()->json($payload, 201);
    }

    /**
     * Bloqueia se as horas planejadas da atividade fizerem o uso do pool passar do
     * liberado à gestão (Project::cronogramaPoolHours). Só vale para etapa em ROLLUP
     * (hours_planned próprio = 0); etapa com horas próprias já é capada pelo guard de
     * etapa. Atividade de cliente não ocupa o pool. NÃO afrouxa com allow_negative_balance.
     */
    private function guardDeliveryPoolCapacity(?ProjectStage $stage, float $newHours, bool $clientInvolved, ?int $excludeDeliveryId): ?JsonResponse
    {
        if ($clientInvolved || !$stage) return null;
        $project = $stage->project;
        if (!$project || !$project->isOperational()) return null;
        if ((float) ($stage->hours_planned ?? 0) > 0) return null; // teto da etapa já controla

        $pool = $project->cronogramaPoolHours();
        $used = $project->plannedPoolUsage($stage->id); // outras etapas (efetivo)
        $sumOther = (float) StageDelivery::where('stage_id', $stage->id)
            ->where('client_involved', false)
            ->where('id', '!=', $excludeDeliveryId ?? 0)
            ->sum('hours_planned');

        if ($used + $sumOther + $newHours > $pool + 0.001) {
            $available = max(0.0, $pool - $used - $sumOther);
            return response()->json([
                'message' => 'Sem saldo no cronograma. O planejamento não pode passar das horas liberadas à gestão.',
                'detail'  => sprintf(
                    'Tentativa de planejar %.1fh nesta atividade. Liberado à gestão: %.1fh · disponível: %.1fh.',
                    $newHours, $pool, $available
                ),
            ], 422);
        }
        return null;
    }

    public function update(Request $request, StageDelivery $delivery): JsonResponse
    {
        $data = $request->validate([
            'title'                  => 'sometimes|string|max:200',
            'description'            => 'nullable|string',
            'responsible_user_id'    => 'nullable|exists:users,id',
            'hours_planned'          => 'sometimes|numeric|min:0',
            'priority'               => ['sometimes', Rule::in(StageDelivery::PRIORITIES)],
            'status'                 => ['sometimes', Rule::in(StageDelivery::STATUSES)],
            'due_date'               => 'nullable|date',
            'planned_start_at'       => 'nullable|date',
            'depends_on_delivery_id' => 'nullable|integer|exists:stage_deliveries,id',
            'dependency_type'        => ['nullable', Rule::in(StageDelivery::DEPENDENCY_TYPES)],
            'client_user_id'         => 'nullable|integer|exists:users,id',
            'client_email'           => 'nullable|email|max:180',
            'client_involved'        => 'nullable|boolean',
            'extra_clients'                => 'nullable|array',
            'extra_clients.*.user_id'      => 'nullable|integer|exists:users,id',
            'extra_clients.*.email'        => 'nullable|email|max:180',
            'extra_clients.*.name'         => 'nullable|string|max:180',
        ]);

        // Guard contra ciclo: atividade não pode depender de si mesma
        if (array_key_exists('depends_on_delivery_id', $data)
            && $data['depends_on_delivery_id'] !== null
            && (int) $data['depends_on_delivery_id'] === (int) $delivery->id) {
            return response()->json([
                'message' => 'Atividade não pode depender de si mesma.',
            ], 422);
        }

        // Ciclo transitivo: o novo predecessor não pode ter $delivery na sua cadeia
        if (array_key_exists('depends_on_delivery_id', $data)
            && $data['depends_on_delivery_id'] !== null
            && $this->hasCycle((int) $data['depends_on_delivery_id'], (int) $delivery->id)) {
            return response()->json([
                'message' => 'Dependência cria ciclo (A depende de B que depende de A).',
            ], 422);
        }

        // Fim não pode ser anterior ao início (considera datas efetivas após a edição).
        $effStart = array_key_exists('planned_start_at', $data) ? $data['planned_start_at'] : optional($delivery->planned_start_at)->toDateString();
        $effEnd   = array_key_exists('due_date', $data) ? $data['due_date'] : optional($delivery->due_date)->toDateString();
        if (!empty($effStart) && !empty($effEnd)
            && substr((string) $effEnd, 0, 10) < substr((string) $effStart, 0, 10)) {
            return response()->json(['message' => 'A data de fim não pode ser anterior ao início.'], 422);
        }

        // Só valida o pool quando AUMENTA as horas planejadas (nunca trava edição/redução).
        if (array_key_exists('hours_planned', $data)
            && (float) $data['hours_planned'] > (float) $delivery->hours_planned) {
            $clientInv = array_key_exists('client_involved', $data)
                ? (bool) $data['client_involved'] : (bool) $delivery->client_involved;
            $err = $this->guardDeliveryPoolCapacity($delivery->stage, (float) $data['hours_planned'], $clientInv, $delivery->id);
            if ($err !== null) return $err;
        }

        $delivery->update($data);

        // Um consultor por atividade: ao trocar o responsável, remove a alocação de
        // qualquer outro consultor (mantém só o do responsável atual).
        if (array_key_exists('responsible_user_id', $data) && $data['responsible_user_id']) {
            \App\Models\StageAllocation::where('delivery_id', $delivery->id)
                ->where('user_id', '!=', (int) $data['responsible_user_id'])
                ->delete();
        }

        // Prazo de entrega do projeto deriva sempre da última data do cronograma.
        $delivery->stage?->project?->recalcExpectedEndFromSchedule();

        $payload = $delivery->fresh()->load(['responsible:id,name,email', 'approvalDecider:id,name'])->toArray();
        if ($suggested = $this->suggestedDueDate($delivery->fresh())) {
            $payload['suggested_due_date'] = $suggested;
        }

        return response()->json($payload);
    }

    public function destroy(StageDelivery $delivery): JsonResponse
    {
        // Fix Fase 9: limpa FK de dependentes antes do soft-delete pra evitar
        // referências fantasma (FK ON DELETE SET NULL não dispara em soft-delete).
        StageDelivery::where('depends_on_delivery_id', $delivery->id)
            ->update(['depends_on_delivery_id' => null, 'dependency_type' => null]);

        $delivery->delete();

        // Remover a última atividade pode antecipar o prazo — recalcula.
        $delivery->stage?->project?->recalcExpectedEndFromSchedule();

        return response()->json(['deleted' => true]);
    }

    /**
     * Move uma entrega: muda status (coluna) e/ou reposiciona dentro da coluna.
     * Payload: { status: 'in_progress', order_index: 2, sibling_ids?: [4,5,7] }
     *
     * Se sibling_ids vier, reordena todas as entregas da nova coluna na ordem informada.
     */
    public function move(Request $request, StageDelivery $delivery): JsonResponse
    {

        $data = $request->validate([
            'status'        => ['required', Rule::in(StageDelivery::STATUSES)],
            'order_index'   => 'sometimes|integer|min:0',
            'sibling_ids'   => 'sometimes|array',
            'sibling_ids.*' => 'integer|exists:stage_deliveries,id',
        ]);

        // Bloqueio operacional: predecessor FS pending impede sair de backlog (ADR 0009 appendix)
        $movingOutOfBacklog = $delivery->status === StageDelivery::STATUS_BACKLOG
            && $data['status'] !== StageDelivery::STATUS_BACKLOG;
        if ($movingOutOfBacklog && $delivery->depends_on_delivery_id && $delivery->dependency_type === 'FS') {
            $predecessor = StageDelivery::find($delivery->depends_on_delivery_id);
            if ($predecessor && $predecessor->status !== StageDelivery::STATUS_DONE) {
                return response()->json([
                    'message' => "Conclua a atividade '{$predecessor->title}' antes de iniciar esta.",
                    'predecessor_id' => $predecessor->id,
                ], 422);
            }
        }

        DB::transaction(function () use ($data, $delivery) {
            $delivery->update([
                'status'      => $data['status'],
                'order_index' => $data['order_index'] ?? $delivery->order_index,
            ]);

            if (!empty($data['sibling_ids'])) {
                foreach ($data['sibling_ids'] as $index => $id) {
                    StageDelivery::where('id', $id)
                        ->where('stage_id', $delivery->stage_id)
                        ->update(['order_index' => $index]);
                }
            }
        });

        return response()->json($delivery->fresh());
    }

    /**
     * Solicita (ou reenvia) a aprovação do cliente. Interno: coordenador/admin.
     */
    public function requestApproval(StageDelivery $delivery, Request $request, DeliveryApprovalService $service): JsonResponse
    {
        // Qualquer perfil interno pode solicitar aprovação (mover p/ aguardando cliente).
        // Cliente já é bloqueado pelo middleware do grupo de rotas.

        // Mover p/ "Aguardando cliente" exige mensagem; anexo/print opcional.
        $data = $request->validate([
            'message'     => 'required|string|max:5000',
            'attachment'  => 'nullable|file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
            'audiences'   => 'nullable|array',
            'audiences.*' => 'in:cliente,consultor',
        ]);

        // Entra em "Aguardando cliente" (observer cria a aprovação pendente) ou,
        // se já estiver, reabre/garante a pendência (reenvio).
        if ($delivery->status !== StageDelivery::STATUS_WAITING_CLIENT) {
            $delivery->update(['status' => StageDelivery::STATUS_WAITING_CLIENT]);
        } else {
            $service->requestApproval($delivery, $request->user());
        }

        // Mensagem obrigatória na conversa, visível ao cliente.
        $service->postApprovalMessage(
            $delivery, $request->user(), $data['message'], $request->file('attachment'),
            array_values(array_diff($data['audiences'] ?? [], ['cliente'])) // 'cliente' já entra por padrão
        );

        return response()->json($delivery->fresh()->load(['responsible:id,name,email', 'approvalDecider:id,name']));
    }

    /**
     * Aprova a atividade em nome do cliente (interno). Move pra Homologação.
     */
    public function approve(StageDelivery $delivery, Request $request, DeliveryApprovalService $service): JsonResponse
    {
        if (($err = $this->ensureCanManageApproval($request)) !== null) return $err;

        $data = $request->validate(['note' => 'nullable|string|max:5000']);
        $service->decide($delivery, $request->user(), true, $data['note'] ?? null, fromClient: false);

        return response()->json($delivery->fresh()->load(['responsible:id,name,email', 'approvalDecider:id,name']));
    }

    /**
     * Reprova / solicita ajustes (interno). Volta pra Em andamento.
     */
    public function reject(StageDelivery $delivery, Request $request, DeliveryApprovalService $service): JsonResponse
    {
        if (($err = $this->ensureCanManageApproval($request)) !== null) return $err;

        $data = $request->validate(['note' => 'nullable|string|max:5000']);
        $service->decide($delivery, $request->user(), false, $data['note'] ?? null, fromClient: false);

        return response()->json($delivery->fresh()->load(['responsible:id,name,email', 'approvalDecider:id,name']));
    }

    private function ensureCanManageApproval(Request $request): ?JsonResponse
    {
        $user = $request->user();
        $can = $user && (
            (method_exists($user, 'isAdmin') && $user->isAdmin())
            || (method_exists($user, 'isCoordenador') && $user->isCoordenador())
        );
        if (!$can) {
            return response()->json(['message' => 'Apenas coordenador ou admin podem gerir a aprovação.'], 403);
        }
        return null;
    }

    /**
     * Timeline da atividade — eventos com delivery_id=X. Mais recentes primeiro.
     * Reaproveita a tabela stage_activity_events (ADR 0005 + 0010).
     */
    public function activity(StageDelivery $delivery, Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 50), 200);
        $user = $request->user();

        $events = \App\Models\StageActivityEvent::query()
            ->where('delivery_id', $delivery->id)
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            // Filtra comentários pelo público (admin/coord veem tudo; consultor vê
            // o que for marcado p/ consultor ou que ele próprio escreveu).
            ->filter(fn ($e) => $e->visibleTo($user))
            ->values();

        return response()->json(['items' => $events]);
    }

    /**
     * Comentário operacional na atividade (Pilar A do refactor).
     *
     * Cria evento append-only `type=comment` em `stage_activity_events`
     * com `delivery_id` setado. Texto livre, anexo opcional, mentions
     * via `mentioned_user_ids`.
     *
     * Filtro de escrita: consultor só comenta em atividade onde é
     * `responsible_user_id` OU está alocado (stage_allocations.delivery_id).
     */
    public function storeComment(Request $request, StageDelivery $delivery): JsonResponse
    {
        $user = $request->user();

        if ($user && method_exists($user, 'isConsultor') && $user->isConsultor()) {
            $isResponsible = (int) $delivery->responsible_user_id === (int) $user->id;
            $isAllocated = \App\Models\StageAllocation::query()
                ->where('user_id', $user->id)
                ->where(function ($q) use ($delivery) {
                    $q->where('delivery_id', $delivery->id)
                      ->orWhere(function ($s) use ($delivery) {
                          $s->whereNull('delivery_id')
                            ->where('stage_id', $delivery->stage_id);
                      });
                })
                ->exists();
            if (!$isResponsible && !$isAllocated) {
                return response()->json([
                    'message' => 'Você não está alocado nesta atividade.',
                ], 403);
            }
        }

        $data = $request->validate([
            'text'                 => 'nullable|string|max:5000',
            'attachment'           => 'nullable|file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
            'mentioned_user_ids'   => 'nullable|array',
            'mentioned_user_ids.*' => 'integer|exists:users,id',
            'audiences'            => 'nullable|array',
            'audiences.*'          => 'in:cliente,consultor',
        ]);

        $text       = trim((string) ($data['text'] ?? ''));
        $hasAttach  = $request->hasFile('attachment');
        $mentioned  = array_map('intval', $data['mentioned_user_ids'] ?? []);
        $audiences  = array_values(array_unique($data['audiences'] ?? []));

        if ($text === '' && !$hasAttach) {
            return response()->json([
                'message' => 'Comentário precisa de texto ou anexo.',
            ], 422);
        }

        $attachmentData = [];
        if ($hasAttach) {
            $file = $request->file('attachment');
            $path = $file->store("stage-attachments/{$delivery->stage_id}", 'public');
            $attachmentData = [
                'attachment_path'          => $path,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime'          => $file->getMimeType(),
                'attachment_size'          => $file->getSize(),
            ];
        }

        $event = \App\Models\StageActivityEvent::create(array_merge([
            'stage_id'      => $delivery->stage_id,
            'delivery_id'   => $delivery->id,
            'actor_user_id' => $user?->id,
            'type'          => \App\Models\StageActivityEvent::TYPE_COMMENT,
            'audiences'     => $audiences,
            'payload'       => array_filter([
                'text'               => $text !== '' ? $text : null,
                'mentioned_user_ids' => !empty($mentioned) ? $mentioned : null,
            ]),
        ], $attachmentData));

        return response()->json($event->load('actor:id,name,email'), 201);
    }

    /**
     * Cascade FS: para cada dependente direto/transitivo,
     * sugere novo start = nextBusinessDay(predecessor.due_date) e
     * novo end = addBusinessHours(novo_start, hours, 8).
     *
     * Body: { apply: bool }. apply=false retorna preview; apply=true persiste.
     */
    public function recalcDependents(Request $request, StageDelivery $delivery): JsonResponse
    {
        $apply = (bool) $request->boolean('apply', false);
        $calendar = app(BusinessCalendarService::class);

        // Preview com valor *que o coord está digitando* sem persistir.
        // Aceito apenas quando apply=false.
        $simulate = $request->input('simulate', []);
        if (!$apply && is_array($simulate) && !empty($simulate)) {
            if (array_key_exists('planned_start_at', $simulate)) {
                $delivery->planned_start_at = $simulate['planned_start_at'] ? Carbon::parse($simulate['planned_start_at']) : null;
            }
            if (array_key_exists('due_date', $simulate)) {
                $delivery->due_date = $simulate['due_date'] ? Carbon::parse($simulate['due_date']) : null;
            }
            if (array_key_exists('hours_planned', $simulate)) {
                $delivery->hours_planned = $simulate['hours_planned'];
            }
        }

        $chain = [];
        $visited = [];

        $walk = function (StageDelivery $node, ?Carbon $newEnd) use (&$walk, &$chain, &$visited, $calendar) {
            // FS é o único tipo de dependência hoje; trata null como FS (a coluna
            // "Depende de" seta só o depends_on_delivery_id, sem o dependency_type).
            $deps = StageDelivery::where('depends_on_delivery_id', $node->id)
                ->where(fn ($q) => $q->where('dependency_type', 'FS')->orWhereNull('dependency_type'))
                ->get();

            foreach ($deps as $dep) {
                if (isset($visited[$dep->id])) continue;
                $visited[$dep->id] = true;

                $hours = (float) ($dep->hours_planned ?? 0);
                $predEnd = $newEnd ?: ($node->due_date ? Carbon::parse($node->due_date) : null);
                if (!$predEnd) continue;

                $suggestedStart = $calendar->nextBusinessDay($predEnd->copy()->addDay());
                $suggestedEnd   = $hours > 0
                    ? $calendar->addBusinessHours($suggestedStart, $hours, 8.0)
                    : $suggestedStart;

                $chain[] = [
                    'id'              => $dep->id,
                    'title'           => $dep->title,
                    'current_start'   => $dep->planned_start_at?->toDateString(),
                    'current_end'     => $dep->due_date?->toDateString(),
                    'suggested_start' => $suggestedStart->toDateString(),
                    'suggested_end'   => $suggestedEnd->toDateString(),
                    'hours_planned'   => $hours,
                ];

                $walk($dep, $suggestedEnd);
            }
        };

        $startEnd = $delivery->due_date ? Carbon::parse($delivery->due_date) : null;
        $walk($delivery, $startEnd);

        if (!$apply) {
            return response()->json(['chain' => $chain]);
        }

        $updatedIds = [];
        DB::transaction(function () use ($chain, &$updatedIds) {
            foreach ($chain as $row) {
                $dep = StageDelivery::find($row['id']);
                if (!$dep) continue;
                $dep->update([
                    'planned_start_at' => $row['suggested_start'],
                    'due_date'         => $row['suggested_end'],
                ]);
                $updatedIds[] = $dep->id;
            }
        });

        return response()->json([
            'chain'   => $chain,
            'updated' => $updatedIds,
        ]);
    }

    private function suggestedDueDate(?StageDelivery $delivery): ?string
    {
        if (!$delivery) return null;
        if (!$delivery->planned_start_at) return null;
        $hours = (float) ($delivery->hours_planned ?? 0);
        if ($hours <= 0) return null;

        return app(BusinessCalendarService::class)
            ->addBusinessHours(Carbon::parse($delivery->planned_start_at), $hours, 8.0)
            ->toDateString();
    }

    /**
     * BFS no grafo de dependências FS a partir de $startId. Retorna true se
     * encontrar $targetId na cadeia (profundidade máxima 10).
     */
    private function hasCycle(int $startId, int $targetId): bool
    {
        $queue = [$startId];
        $seen = [];
        $depth = 0;

        while (!empty($queue) && $depth < 10) {
            $next = [];
            foreach ($queue as $id) {
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                if ($id === $targetId) return true;

                $parent = StageDelivery::where('id', $id)->value('depends_on_delivery_id');
                if ($parent) $next[] = (int) $parent;
            }
            $queue = $next;
            $depth++;
        }

        return false;
    }
}
