<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\HelpDeskCategory;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTeam;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketComment;
use App\Models\HelpDeskTicketEvent;
use App\Services\HelpDeskSlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Help Desk — Chamados: CRUD, filtros, mudança de status, atribuição, interações, timeline, anexos. */
class HelpDeskTicketController extends Controller
{
    public function __construct(private HelpDeskSlaService $sla, private \App\Services\UsageTelemetry $telemetry, private \App\Services\HelpDeskAccessPolicy $access)
    {
    }

    private function withRels($q)
    {
        return $q->with([
            'customer:id,name', 'contact:id,name,email', 'requester:id,name',
            'category:id,name,color', 'status:id,key,label,color,is_open,is_resolved,is_terminal',
            'assignee:id,name', 'team:id,name',
            'contract:id,categoria,helpdesk_integration_enabled', 'project:id,name',
            'service:id,name,code', 'justification:id,name,status_id',
        ]);
    }

    private function decorate(HelpDeskTicket $t, ?\Illuminate\Support\Collection $events = null): array
    {
        return array_merge($t->toArray(), ['sla' => $this->sla->summary($t, $events)]);
    }

    /** Carrega os eventos status_changed de vários tickets em UMA query (anti-N+1 do SLA pausado). */
    private function eventsByTicket(\Illuminate\Support\Collection $tickets): \Illuminate\Support\Collection
    {
        if ($tickets->isEmpty()) return collect();
        return HelpDeskTicketEvent::whereIn('ticket_id', $tickets->pluck('id'))
            ->where('event_type', 'status_changed')->orderBy('created_at')
            ->get(['ticket_id', 'from_value', 'to_value', 'created_at'])
            ->groupBy('ticket_id');
    }

    private function filtered(Request $request)
    {
        $user = $request->user();
        return $this->access->applyViewScope($this->withRels(HelpDeskTicket::query()), $user) // perfil: escopo de visão
            ->when($request->filled('status_id'), fn ($q) => $q->where('status_id', $request->status_id))
            ->when($request->filled('status_key'), fn ($q) => $q->whereHas('status', fn ($s) => $s->where('key', $request->status_key)))
            ->when($request->boolean('open'), fn ($q) => $q->whereHas('status', fn ($s) => $s->where('is_open', true)))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->priority))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->contract_id))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->project_id))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('team_id'), fn ($q) => $q->where('team_id', $request->team_id))
            ->when($request->filled('assignee_id'), fn ($q) => $q->where('assignee_id', $request->assignee_id))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assignee_id', $user?->id))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('assignee_id'))
            ->when($request->boolean('breached'), fn ($q) => $q->where(fn ($w) => $w->where('first_response_breached', true)->orWhere('resolution_breached', true)))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%' . $request->search . '%';
                $q->where(fn ($w) => $w->where('subject', 'ilike', $s)->orWhere('ticket_number', 'ilike', $s)->orWhere('description', 'ilike', $s));
            })
            ->orderByDesc('updated_at');
    }

    public function index(Request $request): JsonResponse
    {
        $tickets = $this->filtered($request)->limit((int) $request->input('limit', 200))->get();
        $events = $this->eventsByTicket($tickets);
        return response()->json(['data' => $tickets->map(fn ($t) => $this->decorate($t, $events->get($t->id) ?? collect()))]);
    }

    public function show(HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canSee(\Illuminate\Support\Facades\Auth::user(), $ticket), 403, 'Seu perfil de acesso não permite ver este chamado.');
        $ticket->load([
            'customer:id,name,cgc', 'contact:id,name,email,phone', 'requester:id,name',
            'category:id,name,color,sla_policy_id', 'status', 'assignee:id,name', 'team:id,name',
            'slaPolicy:id,name', 'contract:id', 'project:id,name',
            'service:id,name,code', 'justification:id,name,status_id',
            'tags:id,name,color', 'watchers',
        ]);
        $events = $ticket->events()->where('event_type', 'status_changed')->orderBy('created_at')->get(['from_value', 'to_value', 'created_at']);
        $data = $this->decorate($ticket, $events);
        $data['can_edit_description'] = $this->access->canEditActions(\Illuminate\Support\Facades\Auth::user());
        // Solicitante resolvido: se o e-mail estiver cadastrado, traz o NOME do contato.
        $data['solicitante'] = ['name' => $ticket->solicitanteName(), 'email' => $ticket->solicitanteEmail()];
        return response()->json(['data' => $data]);
    }

    /**
     * Customer 360 Operacional do chamado: contexto completo do cliente sem sair da tela.
     * Núcleo `Customer360Service` + `Customer360HelpDeskPresenter` (redação financeira por perfil).
     * Acesso segue o chamado (FORK 2): quem tem help_desk.tickets.view recebe o contexto.
     */
    public function context(Request $request, HelpDeskTicket $ticket, \App\Http\Presenters\Customer360HelpDeskPresenter $presenter): JsonResponse
    {
        abort_unless($this->access->canSee($request->user(), $ticket), 403);
        $customer = $ticket->customer;
        if (!$customer) {
            return response()->json(['data' => null]); // chamado interno (sem cliente) → sem contexto 360
        }
        return response()->json(['data' => $presenter->present($customer, $ticket, $request->user())]);
    }

    /** Customer 360 SEM chamado (torre de operações: "Abrir Customer 360" do cliente em risco). */
    public function customerContext(Request $request, \App\Models\Customer $customer, \App\Http\Presenters\Customer360HelpDeskPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $presenter->present($customer, null, $request->user())]);
    }

    /**
     * Redistribuição em lote — reatribui vários chamados a um consultor (ou desatribui).
     * Reúsa a MESMA regra de atribuição (evento assigned), numa transação atômica.
     * Acionado pela Central de Operações ao identificar consultor sobrecarregado.
     */
    public function bulkAssign(Request $request): JsonResponse
    {
        $v = $request->validate([
            'ticket_ids'   => 'required|array|min:1',
            'ticket_ids.*' => 'integer|exists:helpdesk_tickets,id',
            'assignee_id'  => 'nullable|exists:users,id',
        ]);
        if (!empty($v['assignee_id'])) {
            abort_unless($this->access->canBeAssignee(\App\Models\User::find($v['assignee_id'])), 422, 'O agente selecionado não pode ser responsável (perfil de acesso).');
        }
        $count = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($v, &$count) {
            $tickets = HelpDeskTicket::whereIn('id', $v['ticket_ids'])->lockForUpdate()->get();
            foreach ($tickets as $t) {
                $old = $t->assignee_id;
                if ((int) $old === (int) ($v['assignee_id'] ?? 0)) continue;
                $t->assignee_id = $v['assignee_id'] ?? null;
                $t->last_activity_at = now();
                $t->save();
                HelpDeskTicketEvent::log($t->id, 'assigned', ['field' => 'assignee', 'from_value' => (string) $old, 'to_value' => (string) $t->assignee_id]);
                $count++;
            }
        });
        // Telemetria: redistribuição efetuada pela Central de Operações.
        $this->telemetry->record('redistribute', 'performed', [
            'user_id' => $request->user()?->id,
            'metadata' => ['reassigned' => $count, 'to_assignee_id' => $v['assignee_id'] ?? null],
        ]);
        return response()->json(['data' => ['reassigned' => $count]]);
    }

    private function rules(bool $creating): array
    {
        return [
            'subject'             => ($creating ? 'required' : 'sometimes') . '|string|max:200',
            'description'         => 'nullable|string',
            'customer_id'         => 'nullable|exists:customers,id',
            'customer_contact_id' => 'nullable|exists:customer_contacts,id',
            'requester_user_id'   => 'nullable|exists:users,id',
            'cc_emails'           => 'nullable|array',
            'cc_emails.*'         => 'email',
            'contract_id'         => 'nullable|exists:contracts,id',
            'project_id'          => 'nullable|exists:projects,id',
            'category_id'         => 'nullable|exists:helpdesk_categories,id',
            'service_id'          => 'nullable|exists:helpdesk_services,id',
            'justification_id'    => 'nullable|exists:helpdesk_ticket_justifications,id',
            'status_id'           => 'nullable|exists:helpdesk_statuses,id',
            'priority'            => 'nullable|in:' . implode(',', HelpDeskTicket::PRIORITIES),
            'channel'             => 'nullable|in:' . implode(',', HelpDeskTicket::CHANNELS),
            'level'               => 'nullable|in:N1,N2,N3',
            'assignee_id'         => 'nullable|exists:users,id',
            'team_id'             => 'nullable|exists:helpdesk_teams,id',
            'sla_policy_id'       => 'nullable|exists:helpdesk_sla_policies,id',
            'tag_ids'             => 'nullable|array',
            'tag_ids.*'           => 'integer|exists:helpdesk_tags,id',
        ];
    }

    /** Sugestões de e-mail p/ o CC: usuários cadastrados + contatos de clientes. */
    public function emailSuggestions(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('search', ''));
        $users = \App\Models\User::query()->whereNotNull('email')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) =>
                $x->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")))
            ->orderBy('name')->limit(10)->get(['name', 'email'])
            ->map(fn ($u) => ['name' => $u->name, 'email' => $u->email, 'source' => 'Usuário']);

        $contacts = \App\Models\CustomerContact::query()->whereNotNull('email')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) =>
                $x->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")))
            ->orderBy('name')->limit(10)->get(['name', 'email'])
            ->map(fn ($c) => ['name' => $c->name, 'email' => $c->email, 'source' => 'Contato']);

        // Dedup por e-mail (usuário tem prioridade).
        $merged = $users->concat($contacts)->unique(fn ($r) => mb_strtolower($r['email']))->values()->take(15);
        return response()->json(['data' => $merged]);
    }

    /** Busca de contatos p/ trocar o solicitante (por nome ou e-mail). */
    public function searchContacts(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('search', ''));
        $rows = \App\Models\CustomerContact::query()
            ->with('customer:id,name')
            ->when($q !== '', fn ($qq) => $qq->where(fn ($w) =>
                $w->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")))
            ->orderBy('name')->limit(20)
            ->get(['id', 'name', 'email', 'customer_id']);
        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->access->canOpen($request->user()), 403, 'Seu perfil de acesso não permite abrir chamados.');
        $v = $request->validate($this->rules(true));
        $tagIds = $v['tag_ids'] ?? null;
        unset($v['tag_ids']);
        // Perfil de acesso: ignora campos que o agente não pode informar na abertura.
        $u = $request->user();
        if (!$this->access->informAllowed($u, 'service'))  unset($v['service_id']);
        if (!$this->access->informAllowed($u, 'category')) unset($v['category_id']);
        if (!$this->access->informAllowed($u, 'urgency'))  unset($v['priority']);
        if (!$this->access->informAllowed($u, 'tags'))     $tagIds = null;

        $v['priority']      = $v['priority'] ?? 'normal';
        $v['channel']       = $v['channel'] ?? 'interno';
        $v['status_id']     = $v['status_id'] ?? optional(HelpDeskStatus::default())->id;
        $v['created_by_id'] = $request->user()?->id;
        // Categoria pode trazer fila padrão se nenhuma foi informada.
        if (empty($v['team_id']) && !empty($v['category_id'])) {
            $v['team_id'] = optional(HelpDeskCategory::find($v['category_id']))->default_team_id;
        }
        $v['last_activity_at'] = now();

        $ticket = HelpDeskTicket::create($v);
        $ticket->update(['ticket_number' => 'HD-' . str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)]);
        if ($tagIds) $ticket->tags()->sync($tagIds);

        // SLA: resolve política + prazos a partir da prioridade.
        $this->sla->apply($ticket);

        HelpDeskTicketEvent::log($ticket->id, 'created', ['to_value' => $ticket->subject]);
        \App\Services\HelpDeskTriggerEngine::dispatch('ticket_created', $ticket, ['actor_id' => $u?->id, 'actor_email' => $u?->email]);

        return response()->json(['data' => $this->decorate($this->withRels(HelpDeskTicket::query())->find($ticket->id))], 201);
    }

    public function update(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canEdit($request->user(), $ticket), 403, 'Seu perfil não permite editar este chamado.');
        $v = $request->validate($this->rules(false));
        $tagIds = $v['tag_ids'] ?? null;
        unset($v['tag_ids']);

        $oldPriority = $ticket->priority;
        $oldAssignee = $ticket->assignee_id;
        $oldTeam     = $ticket->team_id;

        $ticket->update($v);
        if ($tagIds !== null) $ticket->tags()->sync($tagIds);

        // Trocou o solicitante: deriva nome/e-mail/empresa do contato escolhido.
        if (array_key_exists('customer_contact_id', $v) && $v['customer_contact_id']) {
            if ($ct = \App\Models\CustomerContact::find($v['customer_contact_id'])) {
                $ticket->update(['requester_name' => $ct->name, 'requester_email' => $ct->email, 'customer_id' => $ct->customer_id]);
            }
        }

        if (array_key_exists('priority', $v) && $ticket->priority !== $oldPriority) {
            HelpDeskTicketEvent::log($ticket->id, 'priority_changed', ['field' => 'priority', 'from_value' => $oldPriority, 'to_value' => $ticket->priority]);
            // Reprioritização recalcula os prazos de SLA (a menos que haja override de política).
            $this->sla->apply($ticket->fresh());
            $ticket->refresh();
        }
        if (array_key_exists('assignee_id', $v) && (int) $ticket->assignee_id !== (int) $oldAssignee) {
            HelpDeskTicketEvent::log($ticket->id, 'assigned', ['field' => 'assignee', 'from_value' => (string) $oldAssignee, 'to_value' => (string) $ticket->assignee_id]);
        }
        if (array_key_exists('team_id', $v) && (int) $ticket->team_id !== (int) $oldTeam) {
            HelpDeskTicketEvent::log($ticket->id, 'team_changed', ['field' => 'team', 'from_value' => (string) $oldTeam, 'to_value' => (string) $ticket->team_id]);
        }
        $ticket->update(['last_activity_at' => now()]);

        $u = $request->user();
        if (array_key_exists('assignee_id', $v) && (int) $ticket->assignee_id !== (int) $oldAssignee) {
            \App\Services\HelpDeskTriggerEngine::dispatch('assigned', $ticket->fresh(), ['actor_id' => $u?->id, 'actor_email' => $u?->email, 'was_assigned' => !empty($oldAssignee), 'previous_assignee_id' => $oldAssignee]);
        }
        \App\Services\HelpDeskTriggerEngine::dispatch('field_changed', $ticket->fresh(), ['actor_id' => $u?->id, 'actor_email' => $u?->email]);

        return response()->json(['data' => $this->decorate($this->withRels(HelpDeskTicket::query())->find($ticket->id))]);
    }

    /** Mudança de status (endpoint dedicado). Reusa transitionStatus. */
    public function changeStatus(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        $v = $request->validate([
            'status_id'        => 'required|exists:helpdesk_statuses,id',
            'note'             => 'nullable|string|max:500',
            'justification_id' => 'nullable|exists:helpdesk_ticket_justifications,id', // motivo vinculado ao status
        ]);
        abort_unless($this->access->canEdit($request->user(), $ticket), 403, 'Seu perfil não permite editar este chamado.');
        $new = HelpDeskStatus::find($v['status_id']);
        // Reabertura: status alvo aberto vindo de resolvido/encerrado.
        $isReopen = $new && $new->is_open && ($ticket->status && ($ticket->status->is_resolved || $ticket->status->is_terminal));
        abort_if($isReopen && !$this->access->canReopen($request->user()), 422, 'Seu perfil não permite reabrir chamados.');
        if (array_key_exists('justification_id', $v)) {
            $ticket->justification_id = $v['justification_id'];
            $ticket->save();
        }
        $this->transitionStatus($ticket, $new, $v['note'] ?? null);
        $u = $request->user();
        \App\Services\HelpDeskTriggerEngine::dispatch('status_changed', $ticket->fresh(), ['actor_id' => $u?->id, 'actor_email' => $u?->email]);
        return response()->json(['data' => $this->decorate($this->withRels(HelpDeskTicket::query())->find($ticket->id))]);
    }

    /** Transição de status com efeitos de SLA (resolução/reabertura/fechamento). Reutilizável. */
    private function transitionStatus(HelpDeskTicket $ticket, HelpDeskStatus $new, ?string $note = null): void
    {
        if ((int) $new->id === (int) $ticket->status_id) {
            return;
        }
        $old = $ticket->status;
        $ticket->status_id = $new->id;

        if ($new->is_resolved && !$ticket->resolved_at) {
            $ticket->resolved_at = now();
            HelpDeskTicketEvent::log($ticket->id, 'resolved', ['to_value' => $new->label]);
            // Telemetria: resolução com/sem apontamento (fonte única; vale p/ finalize e mudança de status).
            $this->telemetry->record('ticket', 'resolved', [
                'entity_type' => 'helpdesk_ticket', 'entity_id' => $ticket->id,
                'metadata' => [
                    'has_apontamento' => $ticket->timesheets()->whereNull('deleted_at')->exists(),
                    'reopened_before' => (int) $ticket->reopen_count,
                ],
            ]);
        }
        if ($new->is_open && ($old?->is_resolved || $old?->is_terminal)) {
            $ticket->reopened_at = now();
            $ticket->resolved_at = null;
            $ticket->closed_at   = null;
            $ticket->reopen_count = (int) $ticket->reopen_count + 1;
            HelpDeskTicketEvent::log($ticket->id, 'reopened', ['to_value' => $new->label]);
        }
        if ($new->is_terminal && !$ticket->closed_at) {
            $ticket->closed_at = now();
            HelpDeskTicketEvent::log($ticket->id, 'closed', ['to_value' => $new->label]);
        }

        $this->sla->computeBreaches($ticket);
        $ticket->last_activity_at = now();
        $ticket->save();

        HelpDeskTicketEvent::log($ticket->id, 'status_changed', [
            'field' => 'status', 'from_value' => $old?->key, 'to_value' => $new->key,
            'meta' => $note ? ['note' => $note] : null,
        ]);
    }

    /**
     * FINALIZAR ATENDIMENTO — UMA operação. O consultor "finaliza"; o sistema registra.
     * Cria o apontamento pelo fluxo OFICIAL (TimesheetController::store — ZERO duplicação de
     * regra: saldo/competência/conflito/observer/auditoria), vincula helpdesk_ticket_id, grava
     * a resposta ao cliente e muda o status. Tudo atômico (1 transação). Banco de horas, 360 e
     * fechamento atualizam sozinhos (leem `timesheets`).
     */
    public function finalize(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canEdit($request->user(), $ticket), 403, 'Seu perfil não permite finalizar este chamado.');
        $v = $request->validate([
            'status_id'       => 'required|exists:helpdesk_statuses,id',
            'total_hours'     => ['nullable', 'string'],      // tempo trabalhado (HH:MM | decimal). Vazio = sem apontamento.
            'observation'     => 'nullable|string|max:5000',  // descrição técnica do apontamento
            'reply'           => 'nullable|string',           // resposta ao cliente (opcional)
            'project_id'      => 'nullable|exists:projects,id',
            'idempotency_key' => 'nullable|string|max:80',     // R1 — chave de idempotência gerada pelo FE
        ]);
        $new = HelpDeskStatus::find($v['status_id']);
        $logHours = filled($v['total_hours'] ?? null);
        $key = $v['idempotency_key'] ?? null;

        // R3 — descrição técnica obrigatória quando há apontamento de horas.
        if ($logHours && blank($v['observation'] ?? null)) {
            return response()->json(['code' => 'OBSERVATION_REQUIRED', 'message' => 'A descrição técnica é obrigatória ao registrar horas.'], 422);
        }

        // R2 — inferência de projeto (NUNCA infere projeto de investimento); ambíguo → seletor.
        $projectId = $v['project_id'] ?? $this->resolveProjectForApontamento($ticket);
        if ($logHours && !$projectId) {
            return response()->json([
                'code' => 'PROJECT_REQUIRED',
                'message' => 'Selecione o projeto para registrar as horas.',
                'candidates' => $this->candidateProjects($ticket),
            ], 422);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            // R1 camada 2 — LOCK do chamado: serializa finalizações concorrentes do MESMO chamado.
            HelpDeskTicket::where('id', $ticket->id)->lockForUpdate()->first();

            // R1 camada 1 — IDEMPOTÊNCIA: mesma chave devolve o resultado da 1ª execução (sem novo apontamento).
            if ($key) {
                $done = \App\Models\HelpDeskFinalizeOperation::where('idempotency_key', $key)->first();
                if ($done) {
                    \Illuminate\Support\Facades\DB::commit();
                    return response()->json($done->response, $done->status_code);
                }
            }

            // 1) Apontamento pelo fluxo OFICIAL (se houver tempo).
            if ($logHours) {
                $tsReq = Request::create('', 'POST', [
                    'project_id'         => $projectId,
                    'date'               => now()->toDateString(),
                    'total_hours'        => $v['total_hours'],
                    'observation'        => $v['observation'],
                    'ticket'             => $ticket->ticket_number,
                    'helpdesk_ticket_id' => $ticket->id,
                ]);
                $tsReq->setUserResolver(fn () => $request->user());
                $tsResp = app(\App\Http\Controllers\TimesheetController::class)->store($tsReq);
                if ($tsResp->getStatusCode() !== 201) {
                    \Illuminate\Support\Facades\DB::rollBack(); // falha NÃO é cacheada → retry pode re-tentar
                    return $tsResp;
                }
            }

            // 2) Resposta ao cliente (opcional) — visível ao cliente; fecha 1ª resposta do SLA.
            if (filled($v['reply'] ?? null)) {
                $comment = $ticket->comments()->create([
                    'author_user_id' => $request->user()?->id,
                    'body'           => trim($v['reply']),
                    'visibility'     => 'customer',
                    'channel'        => 'interno',
                ]);
                if (!$ticket->first_responded_at) {
                    $this->sla->registerFirstResponse($ticket);
                }
                HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $comment->id, 'visibility' => 'customer']]);
            }

            // 3) Status (resolver/encerrar).
            $this->transitionStatus($ticket, $new);

            $payload = ['data' => $this->decorate($this->withRels(HelpDeskTicket::query())->find($ticket->id))];

            // R1 — grava idempotência (SÓ sucesso). Unique = backstop p/ corrida em tickets distintos.
            if ($key) {
                try {
                    \App\Models\HelpDeskFinalizeOperation::create([
                        'idempotency_key' => $key, 'helpdesk_ticket_id' => $ticket->id,
                        'user_id' => $request->user()?->id, 'status_code' => 200, 'response' => $payload,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    \Illuminate\Support\Facades\DB::rollBack(); // concorrente já gravou a chave → devolve a 1ª
                    $done = \App\Models\HelpDeskFinalizeOperation::where('idempotency_key', $key)->first();
                    return $done ? response()->json($done->response, $done->status_code) : response()->json($payload);
                }
            }

            \Illuminate\Support\Facades\DB::commit();
            // Telemetria: adoção do fluxo "Finalizar Atendimento" (após o commit; nunca quebra a operação).
            $this->telemetry->record('finalize', 'used', [
                'user_id' => $request->user()?->id,
                'entity_type' => 'helpdesk_ticket', 'entity_id' => $ticket->id,
                'metadata' => ['hours_logged' => $logHours, 'had_reply' => filled($v['reply'] ?? null), 'resolved' => (bool) $new->is_resolved],
            ]);
            return response()->json($payload);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            throw $e;
        }
    }

    /** Apontamentos vinculados a este chamado (via helpdesk_ticket_id). */
    public function timesheets(HelpDeskTicket $ticket): JsonResponse
    {
        $list = $ticket->timesheets()->with('user:id,name')->orderByDesc('date')->orderByDesc('id')->get()
            ->map(fn ($t) => [
                'id'         => $t->id,
                'data'       => optional($t->date)->toDateString(),
                'consultor'  => $t->user?->name,
                'horas'      => round((float) $t->effort_minutes / 60, 2),
                'status'     => $t->status,
                'observacao' => $t->observation,
            ]);
        return response()->json(['data' => $list]);
    }

    /**
     * Executa um Playbook de Atendimento no chamado — sequência de ações numa única operação,
     * atômica, reusando transitionStatus/comentários/SLA (zero duplicação). `start_finalize` NÃO
     * aplica no servidor: devolve defaults p/ o FE abrir o fluxo "Finalizar" pré-preenchido.
     */
    public function executePlaybook(Request $request, HelpDeskTicket $ticket, \App\Models\Playbook $playbook): JsonResponse
    {
        abort_unless($playbook->scope === 'help_desk' && $playbook->active, 404);
        $a = $playbook->actions ?? [];

        if (!empty($a['start_finalize'])) {
            return response()->json(['data' => [
                'start_finalize' => true,
                'defaults'       => ['reply' => $a['reply'] ?? null, 'status_id' => $a['finalize_status_id'] ?? ($a['status_id'] ?? null)],
                'checklist'      => $a['checklist'] ?? [],
            ]]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($a, $ticket, $request, $playbook) {
            if (!empty($a['priority']) && in_array($a['priority'], HelpDeskTicket::PRIORITIES, true) && $a['priority'] !== $ticket->priority) {
                $old = $ticket->priority; $ticket->priority = $a['priority'];
                HelpDeskTicketEvent::log($ticket->id, 'priority_changed', ['field' => 'priority', 'from_value' => $old, 'to_value' => $ticket->priority]);
            }
            if (!empty($a['team_id']) && (int) $a['team_id'] !== (int) $ticket->team_id) {
                $old = $ticket->team_id; $ticket->team_id = (int) $a['team_id'];
                HelpDeskTicketEvent::log($ticket->id, 'team_changed', ['field' => 'team', 'from_value' => (string) $old, 'to_value' => (string) $ticket->team_id]);
            }
            if (!empty($a['assignee_id']) && (int) $a['assignee_id'] !== (int) $ticket->assignee_id) {
                $old = $ticket->assignee_id; $ticket->assignee_id = (int) $a['assignee_id'];
                HelpDeskTicketEvent::log($ticket->id, 'assigned', ['field' => 'assignee', 'from_value' => (string) $old, 'to_value' => (string) $ticket->assignee_id]);
            }
            $ticket->last_activity_at = now();
            $ticket->save();

            if (filled($a['reply'] ?? null)) {
                $c = $ticket->comments()->create(['author_user_id' => $request->user()?->id, 'body' => $a['reply'], 'visibility' => 'customer', 'channel' => 'interno']);
                if (!$ticket->first_responded_at) $this->sla->registerFirstResponse($ticket);
                HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $c->id, 'visibility' => 'customer']]);
            }
            if (filled($a['internal_comment'] ?? null)) {
                $c = $ticket->comments()->create(['author_user_id' => $request->user()?->id, 'body' => $a['internal_comment'], 'visibility' => 'internal', 'channel' => 'interno']);
                HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $c->id, 'visibility' => 'internal']]);
            }
            if (!empty($a['status_id'])) {
                $st = HelpDeskStatus::find($a['status_id']);
                if ($st) $this->transitionStatus($ticket, $st); // SLA pausa/retoma segue o status alvo
            }
            HelpDeskTicketEvent::log($ticket->id, 'playbook', ['to_value' => $playbook->name, 'meta' => ['playbook_id' => $playbook->id]]);
        });

        // Telemetria: Playbook executado (adoção + ranking de quais playbooks são usados).
        $this->telemetry->record('playbook', 'executed', [
            'user_id' => $request->user()?->id,
            'entity_type' => 'playbook', 'entity_id' => $playbook->id,
            'metadata' => ['playbook_name' => $playbook->name, 'ticket_id' => $ticket->id],
        ]);

        return response()->json(['data' => [
            'start_finalize' => false,
            'checklist'      => $a['checklist'] ?? [],
            'ticket'         => $this->decorate($this->withRels(HelpDeskTicket::query())->find($ticket->id)),
        ]]);
    }

    /**
     * R2 — projeto inferido p/ o apontamento: chamado → contrato(1 ativo) → cliente(1 ativo) → null.
     * NUNCA infere projeto de Investimento Interno (exige Projeto Real no fluxo oficial) — cai no seletor.
     */
    private function resolveProjectForApontamento(HelpDeskTicket $ticket): ?int
    {
        if ($ticket->project_id) {
            $p = \App\Models\Project::find($ticket->project_id);
            return ($p && !$p->is_investimento_comercial) ? (int) $p->id : null;
        }
        $ativos = ['started', 'liberado_para_testes', 'paused', 'awaiting_start'];
        foreach ([['contract_id', $ticket->contract_id], ['customer_id', $ticket->customer_id]] as [$col, $val]) {
            if (!$val) continue;
            $ids = \App\Models\Project::where($col, $val)->whereNull('deleted_at')
                ->where('is_investimento_comercial', false)->whereIn('status', $ativos)->pluck('id');
            if ($ids->count() === 1) return (int) $ids->first();
        }
        return null;
    }

    /** Projetos candidatos quando a inferência é ambígua (FE mostra seletor). Sem projetos de investimento. */
    private function candidateProjects(HelpDeskTicket $ticket): array
    {
        $ativos = ['started', 'liberado_para_testes', 'paused', 'awaiting_start'];
        $q = \App\Models\Project::query()->whereNull('deleted_at')->where('is_investimento_comercial', false)->whereIn('status', $ativos);
        if ($ticket->contract_id) $q->where('contract_id', $ticket->contract_id);
        elseif ($ticket->customer_id) $q->where('customer_id', $ticket->customer_id);
        else return [];
        return $q->orderBy('name')->get(['id', 'name'])->toArray();
    }

    /** Atribui atendente e/ou fila. */
    public function assign(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canEdit($request->user(), $ticket), 403, 'Seu perfil não permite editar este chamado.');
        $v = $request->validate([
            'assignee_id' => 'nullable|exists:users,id',
            'team_id'     => 'nullable|exists:helpdesk_teams,id',
        ]);
        // O alvo precisa poder ser responsável (perfil de acesso).
        if (!empty($v['assignee_id'])) {
            abort_unless($this->access->canBeAssignee(\App\Models\User::find($v['assignee_id'])), 422, 'O agente selecionado não pode ser responsável (perfil de acesso).');
        }
        $oldA = $ticket->assignee_id; $oldT = $ticket->team_id;
        $ticket->fill($v)->save();
        if ((int) $ticket->assignee_id !== (int) $oldA) {
            HelpDeskTicketEvent::log($ticket->id, 'assigned', ['field' => 'assignee', 'from_value' => (string) $oldA, 'to_value' => (string) $ticket->assignee_id]);
        }
        if ((int) $ticket->team_id !== (int) $oldT) {
            HelpDeskTicketEvent::log($ticket->id, 'team_changed', ['field' => 'team', 'from_value' => (string) $oldT, 'to_value' => (string) $ticket->team_id]);
        }
        $u = $request->user();
        if ((int) $ticket->assignee_id !== (int) $oldA) {
            \App\Services\HelpDeskTriggerEngine::dispatch('assigned', $ticket->fresh(), ['actor_id' => $u?->id, 'actor_email' => $u?->email, 'was_assigned' => !empty($oldA), 'previous_assignee_id' => $oldA]);
        }
        return response()->json(['data' => $this->decorate($ticket->fresh())]);
    }

    public function destroy(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canDelete($request->user()), 403, 'Seu perfil não permite excluir chamados.');
        $ticket->delete(); // soft delete
        return response()->json(null, 204);
    }

    // ── Timeline ──────────────────────────────────────────────────────────────
    public function timeline(HelpDeskTicket $ticket): JsonResponse
    {
        $events = $ticket->events()->with('triggeredBy:id,name')->orderBy('created_at')->get();
        return response()->json(['data' => $events]);
    }

    // ── Interações (respostas/notas) ──────────────────────────────────────────
    public function comments(Request $request, HelpDeskTicket $ticket, AttachmentService $svc): JsonResponse
    {
        $q = $ticket->comments()->with(['author:id,name', 'contact:id,name'])->orderBy('created_at');
        // Cliente só enxerga as respostas marcadas como visíveis ao cliente.
        if ($request->user()?->isCliente()) {
            $q->where('visibility', 'customer');
        }
        $user = $request->user();
        // Anexos POR interação (estilo e-mail) — listFor respeita permissão/visibilidade.
        $data = $q->get()->map(function ($c) use ($svc, $user) {
            $arr = $c->toArray();
            $arr['attachments'] = $svc->listFor('HELPDESK_TICKET_COMMENT', $c->id, $user)->values();
            $arr['can_edit'] = $this->access->canEditComment($user, $c);
            return $arr;
        });
        return response()->json(['data' => $data]);
    }

    /** Edita o corpo E o tempo trabalhado de uma interação (gated por service.edit_actions). */
    public function updateComment(Request $request, HelpDeskTicket $ticket, HelpDeskTicketComment $comment, AttachmentService $svc): JsonResponse
    {
        abort_unless($comment->ticket_id === $ticket->id, 404);
        abort_unless($this->access->canEditComment($request->user(), $comment), 403, 'Seu perfil de acesso não permite editar interações.');
        $v = $request->validate([
            'body'        => 'nullable|string',
            'worked_date' => 'nullable|date|before_or_equal:today',
            'start_time'  => 'nullable|date_format:H:i',
            'end_time'    => 'nullable|date_format:H:i|after:start_time',
            'total_hours' => ['nullable', 'string', 'regex:/^(\d+:[0-5][0-9]|\d+(?:[.,]\d{1,2})?)$/'],
            'no_charge'   => 'nullable|boolean',
            'solution'    => 'nullable|array',
            'form_kind'   => 'nullable|string|max:20',
        ]);

        $update = ['body' => $v['body'] ?? $comment->body];
        if ($request->has('solution')) {
            $update['solution'] = $v['solution'] ?? null;
        }
        if ($request->has('form_kind')) {
            $update['form_kind'] = $v['form_kind'] ?? null;
        }
        // Só mexe no tempo se o request trouxe algum campo de tempo (edições antigas só de corpo não zeram).
        $touchedTime = $request->hasAny(['worked_date', 'start_time', 'end_time', 'total_hours', 'no_charge']);
        if ($request->has('no_charge')) {
            $update['no_charge'] = (bool) $v['no_charge'];
        }
        $effortMinutes = null;
        $workedDate = filled($v['worked_date'] ?? null) ? $v['worked_date'] : null;
        if ($touchedTime) {
            $effortMinutes = $this->computeEffortMinutes($v);
            $update['worked_date']    = $workedDate;
            $update['start_time']     = $v['start_time'] ?? null;
            $update['end_time']       = $v['end_time'] ?? null;
            $update['effort_minutes'] = $effortMinutes;

            // Conflito de horário (mesmo cliente) — exclui o próprio apontamento vinculado.
            $noCharge = $request->has('no_charge') ? (bool) $v['no_charge'] : (bool) $comment->no_charge;
            if ($this->apontamentoEligible($ticket, $comment->visibility, $noCharge, $effortMinutes)
                && filled($v['start_time'] ?? null) && filled($v['end_time'] ?? null)) {
                $conflict = $this->findInteractionTimeConflict(
                    $ticket->customer_id, $workedDate ?: now()->toDateString(), $v['start_time'], $v['end_time'], $comment->timesheet_id
                );
                if ($conflict) {
                    return $this->timeConflictResponse($conflict);
                }
            }
        }
        $comment->update($update);

        // Sincroniza o apontamento: se já existe vínculo, atualiza horas/data; se não existe
        // e agora é elegível (resposta ao cliente + integração + projeto), cria.
        $warning = null;
        if ($touchedTime) {
            $warning = $this->maybeCreateInteractionTimesheet($ticket, $comment->fresh(), $effortMinutes, $workedDate, $request);
        }

        HelpDeskTicketEvent::log($ticket->id, 'comment_edited', ['meta' => ['comment_id' => $comment->id]]);

        $arr = $comment->fresh()->load('author:id,name')->toArray();
        $arr['attachments'] = $svc->listFor('HELPDESK_TICKET_COMMENT', $comment->id, $request->user())->values();
        $arr['can_edit'] = true;
        if ($warning) {
            $arr['apontamento_warning'] = $warning;
        }
        return response()->json(['data' => $arr]);
    }

    /** Anexa um arquivo a uma interação existente (usado ao editar). Gated igual à edição. */
    public function addCommentAttachment(Request $request, HelpDeskTicket $ticket, HelpDeskTicketComment $comment, AttachmentService $svc): JsonResponse
    {
        abort_unless($comment->ticket_id === $ticket->id, 404);
        abort_unless($this->access->canEditComment($request->user(), $comment), 403, 'Seu perfil de acesso não permite editar interações.');
        $request->validate(['file' => 'required|file|max:25600']);
        $file = $request->file('file');
        $att = $svc->store($request->user(), [
            'entity_type' => 'HELPDESK_TICKET_COMMENT', 'entity_id' => $comment->id,
            'category'    => str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : 'attachment',
            'visibility'  => $comment->visibility,
            'file'        => $file,
        ], $request);
        return response()->json(['data' => $att], 201);
    }

    public function addComment(Request $request, HelpDeskTicket $ticket, AttachmentService $svc): JsonResponse
    {
        $v = $request->validate([
            'body'            => 'required_without:files|nullable|string', // interação pode ser só anexo/print (estilo e-mail)
            'visibility'      => 'nullable|in:internal,customer',
            'channel'         => 'nullable|string|max:20',
            'files'           => 'nullable|array',
            'files.*'         => 'file|max:25600',
            'idempotency_key' => 'nullable|string|max:80',
            // Tempo trabalhado nesta interação (opcional). Movimenta horas quando a
            // integração do contrato está ligada. total_hours: HH:MM ou decimal.
            'worked_date'     => 'nullable|date|before_or_equal:today',
            'start_time'      => 'nullable|date_format:H:i',
            'end_time'        => 'nullable|date_format:H:i|after:start_time',
            'total_hours'     => ['nullable', 'string', 'regex:/^(\d+:[0-5][0-9]|\d+(?:[.,]\d{1,2})?)$/'],
            'no_charge'       => 'nullable|boolean',
            'solution'        => 'nullable|array', // Detalhamento da Solução / GMUD (estruturado)
            'form_kind'       => 'nullable|string|max:20', // 'solution' | 'gmud'
        ]);
        // Minutos trabalhados: total_hours prevalece; senão deriva de início→fim.
        $effortMinutes = $this->computeEffortMinutes($v);
        $workedDate = filled($v['worked_date'] ?? null) ? $v['worked_date'] : null;
        // Idempotência: reenvio (erro→retry, duplo-clique, request lento) NÃO duplica a interação.
        $key = $v['idempotency_key'] ?? null;
        if ($key) {
            $existing = HelpDeskTicketComment::where('idempotency_key', $key)->first();
            if ($existing) {
                $arr = $existing->load('author:id,name')->toArray();
                $arr['attachments'] = $svc->listFor('HELPDESK_TICKET_COMMENT', $existing->id, $request->user())->values();
                return response()->json(['data' => $arr], 200);
            }
        }

        // Conflito de horário (mesmo CLIENTE): se esta interação for gerar apontamento e
        // tiver início/fim, bloqueia ANTES de gravar quando sobrepõe outro apontamento do
        // mesmo cliente na mesma data.
        if ($this->apontamentoEligible($ticket, $v['visibility'] ?? 'internal', (bool) ($v['no_charge'] ?? false), $effortMinutes)
            && filled($v['start_time'] ?? null) && filled($v['end_time'] ?? null)) {
            $conflict = $this->findInteractionTimeConflict(
                $ticket->customer_id, $workedDate ?: now()->toDateString(), $v['start_time'], $v['end_time'], null
            );
            if ($conflict) {
                return $this->timeConflictResponse($conflict);
            }
        }

        // ATÔMICO: comentário + anexos numa transação. Se um arquivo for inválido (MIME/tamanho),
        // tudo é revertido — NUNCA fica comentário órfão sem o anexo.
        try {
            $comment = \Illuminate\Support\Facades\DB::transaction(function () use ($ticket, $v, $key, $request, $svc, $effortMinutes, $workedDate) {
                $comment = $ticket->comments()->create([
                    'author_user_id'  => $request->user()?->id,
                    'body'            => $v['body'] ?? '',
                    'visibility'      => $v['visibility'] ?? 'internal',
                    'channel'         => $v['channel'] ?? 'interno',
                    'idempotency_key' => $key,
                    'worked_date'     => $workedDate,
                    'start_time'      => $v['start_time'] ?? null,
                    'end_time'        => $v['end_time'] ?? null,
                    'effort_minutes'  => ($effortMinutes && $effortMinutes > 0) ? $effortMinutes : null,
                    'no_charge'       => (bool) ($v['no_charge'] ?? false),
                    'solution'        => $v['solution'] ?? null,
                    'form_kind'       => $v['form_kind'] ?? null,
                ]);
                // Anexos da interação (estilo e-mail: texto + arquivos/prints juntos). Reúsa o motor de anexos.
                foreach ((array) $request->file('files', []) as $file) {
                    $svc->store($request->user(), [
                        'entity_type' => 'HELPDESK_TICKET_COMMENT', 'entity_id' => $comment->id,
                        'category'    => str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : 'attachment',
                        'visibility'  => $comment->visibility,
                        'file'        => $file,
                    ], $request);
                }
                if ($comment->visibility === 'customer' && !$ticket->first_responded_at) {
                    $this->sla->registerFirstResponse($ticket); // 1ª resposta pública fecha SLA de 1ª resposta
                }
                $ticket->update(['last_activity_at' => now()]);
                HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $comment->id, 'visibility' => $comment->visibility]]);
                return $comment;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Corrida na chave de idempotência: outra requisição já gravou → devolve a existente.
            $existing = $key ? HelpDeskTicketComment::where('idempotency_key', $key)->first() : null;
            if ($existing) {
                $arr = $existing->load('author:id,name')->toArray();
                $arr['attachments'] = $svc->listFor('HELPDESK_TICKET_COMMENT', $existing->id, $request->user())->values();
                return response()->json(['data' => $arr], 200);
            }
            throw $e;
        }

        // Integração de horas (substitui Movidesk): interação com tempo + contrato com a
        // chave ligada → gera o apontamento oficial FORA da transação da interação. Best-effort:
        // uma falha de saldo/projeto avisa o usuário, mas nunca perde a interação já gravada.
        $apontamentoWarning = $this->maybeCreateInteractionTimesheet($ticket, $comment, $effortMinutes, $workedDate, $request);

        // Resposta pública → e-mail ao solicitante pelo mesmo OAuth/Graph (Mail.Send).
        // Best-effort: nunca derruba a gravação do comentário.
        try {
            [$sent, $reason] = \App\Services\HelpDeskReplyMailer::sendPublicComment($ticket, $comment);
            if ($sent) {
                HelpDeskTicketEvent::log($ticket->id, 'email_sent', ['meta' => ['comment_id' => $comment->id]]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('HelpDesk: envio de resposta lançou: ' . $e->getMessage());
        }

        // Gatilhos: interação feita por um AGENTE (usuário logado).
        $u = $request->user();
        \App\Services\HelpDeskTriggerEngine::dispatch('comment_added', $ticket->fresh(), [
            'comment_by' => 'agent', 'visibility' => $comment->visibility, 'actor_id' => $u?->id, 'actor_email' => $u?->email,
        ]);

        $arr = $comment->load('author:id,name')->toArray();
        $arr['attachments'] = $svc->listFor('HELPDESK_TICKET_COMMENT', $comment->id, $request->user())->values();
        if ($apontamentoWarning) {
            $arr['apontamento_warning'] = $apontamentoWarning;
        }
        return response()->json(['data' => $arr], 201);
    }

    /**
     * Gera o apontamento oficial a partir de uma interação com tempo, quando o
     * contrato do chamado tem a chave de integração ligada. Retorna uma mensagem
     * de aviso (string) quando NÃO foi possível movimentar as horas — a interação
     * já está gravada; o aviso apenas sinaliza que o apontamento não ocorreu.
     */
    /**
     * Retorna o projectId se a interação GERARIA apontamento (movimenta horas); senão null.
     * Mesmo gate do maybeCreateInteractionTimesheet — usado pelo pré-check de conflito.
     */
    private function apontamentoEligible(HelpDeskTicket $ticket, string $visibility, bool $noCharge, ?int $effortMinutes): ?int
    {
        if (!$effortMinutes || $effortMinutes <= 0) return null;
        if ($noCharge) return null;
        if ($visibility !== 'customer') return null;
        $contract = $ticket->contract_id ? \App\Models\Contract::find($ticket->contract_id) : null;
        if (!$contract || !$contract->helpdesk_integration_enabled) return null;
        return $this->resolveProjectForApontamento($ticket);
    }

    /**
     * Conflito de horário do MESMO CLIENTE: procura um apontamento (timesheet) do cliente na
     * mesma data cujo intervalo [start,end] sobreponha [start,end] informado. Compara em PHP
     * (H:i) p/ ser robusto ao tipo da coluna. Retorna o conflitante ou null.
     */
    private function findInteractionTimeConflict(?int $customerId, ?string $date, ?string $start, ?string $end, ?int $excludeTimesheetId): ?\App\Models\Timesheet
    {
        if (!$customerId || !$date || !$start || !$end) return null;

        $candidates = \App\Models\Timesheet::query()
            ->where('customer_id', $customerId)
            ->whereDate('date', $date)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['rejected'])
            ->whereNotNull('start_time')->whereNotNull('end_time')
            ->when($excludeTimesheetId, fn ($q) => $q->where('id', '!=', $excludeTimesheetId))
            ->with('user:id,name')
            ->get();

        foreach ($candidates as $ts) {
            $s = $ts->start_time instanceof \Carbon\Carbon ? $ts->start_time->format('H:i') : substr((string) $ts->start_time, 0, 5);
            $e = $ts->end_time instanceof \Carbon\Carbon ? $ts->end_time->format('H:i') : substr((string) $ts->end_time, 0, 5);
            // Sobreposição: existe.start < novo.end  E  novo.start < existe.end
            if ($s < $end && $start < $e) {
                return $ts;
            }
        }
        return null;
    }

    /** Resposta 422 padronizada de conflito de horário. */
    private function timeConflictResponse(\App\Models\Timesheet $ts): JsonResponse
    {
        $s = $ts->start_time instanceof \Carbon\Carbon ? $ts->start_time->format('H:i') : substr((string) $ts->start_time, 0, 5);
        $e = $ts->end_time instanceof \Carbon\Carbon ? $ts->end_time->format('H:i') : substr((string) $ts->end_time, 0, 5);
        return response()->json([
            'code'    => 'TIMESHEET_CONFLICT',
            'message' => "Conflito de horário: este cliente já tem um apontamento das {$s} às {$e}"
                . ($ts->user?->name ? " ({$ts->user->name})" : '') . '. Ajuste o horário.',
        ], 422);
    }

    /** Minutos trabalhados: total_hours prevalece; senão deriva de início→fim. Null se ≤0. */
    private function computeEffortMinutes(array $v): ?int
    {
        $m = \App\Models\Timesheet::parseTotalHoursToMinutes($v['total_hours'] ?? null);
        if (($m === null || $m <= 0) && filled($v['start_time'] ?? null) && filled($v['end_time'] ?? null)) {
            $start = \Carbon\Carbon::createFromFormat('H:i', $v['start_time']);
            $end   = \Carbon\Carbon::createFromFormat('H:i', $v['end_time']);
            $m = $end->greaterThan($start) ? $start->diffInMinutes($end) : null;
        }
        return ($m && $m > 0) ? (int) $m : null;
    }

    private function maybeCreateInteractionTimesheet(HelpDeskTicket $ticket, HelpDeskTicketComment $comment, ?int $effortMinutes, ?string $workedDate, Request $request): ?string
    {
        // "NÃO GERA COBRANÇA": nunca movimenta horas, mesmo sendo resposta ao cliente.
        // Se já havia um apontamento (marcado como não-cobrança depois), mantém e avisa.
        if ($comment->no_charge) {
            if ($comment->timesheet_id) {
                $ts = \App\Models\Timesheet::find($comment->timesheet_id);
                return $ts
                    ? 'Interação marcada como "não gera cobrança". O apontamento #' . $ts->id . ' vinculado foi mantido — remova-o manualmente se necessário.'
                    : null;
            }
            return null;
        }

        // EDIÇÃO — a interação JÁ tem apontamento vinculado: sincroniza horas/data/descrição
        // (não recria, não duplica). Espelha os campos da interação no apontamento.
        if ($comment->timesheet_id) {
            $ts = \App\Models\Timesheet::find($comment->timesheet_id);
            if (!$ts) {
                return null; // apontamento sumiu (soft-delete): não recria automaticamente
            }
            if (!$effortMinutes || $effortMinutes <= 0) {
                return 'Tempo removido da interação. O apontamento #' . $ts->id . ' vinculado foi mantido — ajuste ou remova manualmente se necessário.';
            }
            $obs = trim((string) $comment->body);
            $ts->effort_minutes = $effortMinutes;
            $ts->start_time     = $comment->start_time ?: null;
            $ts->end_time       = $comment->end_time ?: null;
            if ($workedDate) {
                $ts->date = $workedDate;
            }
            if ($obs !== '') {
                $ts->observation = $obs;
            }
            $ts->save();
            return null;
        }

        if (!$effortMinutes || $effortMinutes <= 0) {
            return null; // sem tempo → nada a movimentar
        }

        // Só RESPOSTA AO CLIENTE movimenta horas. Nota interna registra o tempo na
        // interação (histórico), mas NÃO gera apontamento.
        if ($comment->visibility !== 'customer') {
            return null;
        }

        $contract = $ticket->contract_id ? \App\Models\Contract::find($ticket->contract_id) : null;
        if (!$contract || !$contract->helpdesk_integration_enabled) {
            return null; // integração desligada: guarda o tempo na interação, mas não movimenta horas
        }

        $projectId = $this->resolveProjectForApontamento($ticket);
        if (!$projectId) {
            \Illuminate\Support\Facades\Log::warning('HelpDesk: interação com tempo sem projeto para apontamento', [
                'ticket_id' => $ticket->id, 'comment_id' => $comment->id,
            ]);
            return 'Tempo registrado na interação, mas não foi possível identificar o projeto para movimentar as horas. Vincule o chamado a um projeto ativo.';
        }

        $hhmm = sprintf('%d:%02d', intdiv($effortMinutes, 60), $effortMinutes % 60);
        $obs  = trim((string) $comment->body);
        if ($obs === '') {
            $obs = 'Atendimento Help Desk — chamado ' . $ticket->ticket_number;
        }

        try {
            // Cria pelo total_hours (não passa início/fim ao store p/ NÃO acionar o conflito
            // por-usuário dele — a regra aqui é por CLIENTE, já pré-checada acima). O intervalo
            // início/fim é gravado no timesheet logo após, p/ conflitos futuros detectarem.
            $tsReq = Request::create('', 'POST', [
                'project_id'         => $projectId,
                'date'               => $workedDate ?: now()->toDateString(),
                'total_hours'        => $hhmm,
                'observation'        => $obs,
                'ticket'             => $ticket->ticket_number,
                'helpdesk_ticket_id' => $ticket->id,
            ]);
            $tsReq->setUserResolver(fn () => $request->user());
            $tsResp = app(\App\Http\Controllers\TimesheetController::class)->store($tsReq);

            if ($tsResp->getStatusCode() === 201) {
                $data = json_decode($tsResp->getContent(), true);
                $tsId = $data['data']['id'] ?? $data['id'] ?? null;
                if ($tsId) {
                    $comment->timesheet_id = $tsId;
                    $comment->save();
                    // Grava o intervalo no apontamento (p/ o conflito por cliente enxergá-lo).
                    if ($comment->start_time && $comment->end_time) {
                        \App\Models\Timesheet::where('id', $tsId)->update([
                            'start_time' => $comment->start_time,
                            'end_time'   => $comment->end_time,
                        ]);
                    }
                }
                return null;
            }

            $body = json_decode($tsResp->getContent(), true);
            \Illuminate\Support\Facades\Log::warning('HelpDesk: apontamento da interação falhou', [
                'ticket_id' => $ticket->id, 'status' => $tsResp->getStatusCode(), 'body' => $body,
            ]);
            return ($body['message'] ?? null) ?: 'Não foi possível movimentar as horas desta interação.';
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('HelpDesk: exceção ao apontar interação: ' . $e->getMessage());
            return 'Tempo registrado, mas houve um erro ao movimentar as horas. Verifique o saldo do contrato.';
        }
    }

    /** Download de anexo de uma interação (HELPDESK_TICKET_COMMENT). */
    public function downloadCommentAttachment(Request $request, HelpDeskTicket $ticket, \App\Models\HelpDeskTicketComment $comment, Attachment $attachment, AttachmentService $svc)
    {
        abort_unless((int) $comment->ticket_id === (int) $ticket->id, 404);
        abort_unless($attachment->entity_type === 'HELPDESK_TICKET_COMMENT' && (int) $attachment->entity_id === $comment->id, 404);
        return $svc->downloadStream($attachment, $request->user(), $request);
    }

    // ── Anexos (Attachment Engine, entity HELPDESK_TICKET) ────────────────────
    public function attachments(Request $request, HelpDeskTicket $ticket, AttachmentService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->listFor('HELPDESK_TICKET', $ticket->id, $request->user())->values()]);
    }

    public function uploadAttachment(Request $request, HelpDeskTicket $ticket, AttachmentService $svc): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:25600']);
        $att = $svc->store($request->user(), [
            'entity_type' => 'HELPDESK_TICKET', 'entity_id' => $ticket->id,
            'category' => 'attachment', 'file' => $request->file('file'),
        ], $request);
        HelpDeskTicketEvent::log($ticket->id, 'attachment_added', ['meta' => ['attachment_id' => $att->id ?? null]]);
        return response()->json(['data' => $att], 201);
    }

    public function downloadAttachment(Request $request, HelpDeskTicket $ticket, Attachment $attachment, AttachmentService $svc)
    {
        abort_unless($attachment->entity_type === 'HELPDESK_TICKET' && (int) $attachment->entity_id === $ticket->id, 404);
        return $svc->downloadStream($attachment, $request->user(), $request);
    }

    public function deleteAttachment(Request $request, HelpDeskTicket $ticket, Attachment $attachment, AttachmentService $svc): JsonResponse
    {
        abort_unless($attachment->entity_type === 'HELPDESK_TICKET' && (int) $attachment->entity_id === $ticket->id, 404);
        $svc->softDelete($attachment, $request->user(), $request);
        return response()->json(null, 204);
    }
}
