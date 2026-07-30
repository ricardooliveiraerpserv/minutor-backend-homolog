<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentService;
use App\Http\Portal\HelpDeskPortalPresenter;
use App\Models\Attachment;
use App\Models\HelpDeskTicketComment;
use App\Models\HelpDeskKbArticle;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketEvent;
use App\Services\HelpDeskSlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Help Desk — Portal do Cliente. Consome EXCLUSIVAMENTE dados locais do Minutor,
 * escopado ao customer_id do usuário cliente. Respostas SEMPRE via HelpDeskPortalPresenter
 * (C2): nunca devolve a entidade completa nem campos internos.
 */
class HelpDeskPortalController extends Controller
{
    public function __construct(private HelpDeskSlaService $sla, private \App\Services\HelpDeskAccessPolicy $access)
    {
    }

    /** customer_id do cliente logado (aborta se o usuário não for cliente vinculado). */
    private function customerId(Request $request): int
    {
        $cid = $request->user()?->customer_id;
        abort_if($cid === null, 403, 'Portal disponível apenas para usuários cliente.');
        return (int) $cid;
    }

    /**
     * Escopo "departamento": chamados abertos por pessoas do MESMO departamento do cliente.
     * O chamado não tem departamento próprio — ele herda o departamento de quem o abriu
     * (requester). Cliente sem departamento definido só enxerga os próprios (fallback seguro).
     */
    private function scopeByDepartment(\Illuminate\Database\Eloquent\Builder $q, \App\Models\User $user, int $cid): \Illuminate\Database\Eloquent\Builder
    {
        $dept = $user->helpdesk_department_id;
        if (!$dept) return $q->where('requester_user_id', $user->id);
        return $q->whereIn('requester_user_id', \App\Models\User::query()
            ->where('customer_id', $cid)->where('helpdesk_department_id', $dept)->select('id'));
    }

    /** Eventos status_changed de vários tickets em UMA query (SLA pausado sem N+1). */
    private function eventsByTicket(Collection $tickets): Collection
    {
        if ($tickets->isEmpty()) return collect();
        return HelpDeskTicketEvent::whereIn('ticket_id', $tickets->pluck('id'))
            ->where('event_type', 'status_changed')->orderBy('created_at')
            ->get(['ticket_id', 'from_value', 'to_value', 'created_at'])
            ->groupBy('ticket_id');
    }

    /** Garante que o chamado pertence à empresa do cliente. */
    private function ownTicket(Request $request, HelpDeskTicket $ticket): void
    {
        $u = $request->user();
        // SOLICITANTE do chamado — mesmo sendo agente/admin (sem customer_id): o chamado é dele,
        // então pode ver/aceitar/recusar. Casa por user_id OU por e-mail (chamado de origem e-mail
        // não tem requester_user_id). Só depois disso cai na regra de "cliente da mesma customer".
        if ($u && (
            ($ticket->requester_user_id && (int) $ticket->requester_user_id === (int) $u->id)
            || ($ticket->requester_email && $u->email && strcasecmp((string) $ticket->requester_email, (string) $u->email) === 0)
        )) {
            return;
        }
        abort_unless((int) $ticket->customer_id === $this->customerId($request), 404);
    }

    /** Anexos públicos (visíveis ao cliente) de um chamado. */
    private function publicAttachments(HelpDeskTicket $ticket): Collection
    {
        return Attachment::query()->forEntity('HELPDESK_TICKET', $ticket->id)
            ->where('visibility', 'customer')->whereNull('deleted_at')
            ->orderBy('created_at')->get();
    }

    // ── Chamados do cliente ───────────────────────────────────────────────────
    /** Permissões do cliente logado (p/ o Portal esconder campos/botões). */
    public function permissions(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json(['data' => [
            'can_open' => $this->access->clientCanOpen($u),
            'inform'   => $this->access->informMap($u, ['service', 'category', 'urgency', 'subject', 'tags']),
        ]]);
    }

    /** Colunas do Kanban do cliente: status ATIVOS e não-terminais (ordem do cadastro). */
    public function statuses(): JsonResponse
    {
        $rows = HelpDeskStatus::where('active', true)->where('is_terminal', false)
            ->orderBy('sort_order')->orderBy('id')->get(['label', 'color']);
        return response()->json(['data' => $rows->map(fn ($s) => ['label' => $s->label, 'cor' => $s->color])->values()]);
    }

    public function myTickets(Request $request): JsonResponse
    {
        $cid = $this->customerId($request);
        $user = $request->user();
        $scope = $this->access->clientViewScope($user); // own | department | same_org | none
        $tickets = HelpDeskTicket::where('customer_id', $cid)
            ->whereNull('merged_into_id') // chamados mesclados não aparecem na lista do cliente
            ->when($scope === 'own', fn ($q) => $q->where('requester_user_id', $user->id)) // só os que ELE abriu
            ->when($scope === 'department', fn ($q) => $this->scopeByDepartment($q, $user, $cid)) // do mesmo depto dele
            ->when($scope === 'none', fn ($q) => $q->whereRaw('1 = 0'))
            ->with(['status:id,key,label,color,is_open,is_resolved,is_terminal,sla_paused', 'assignee:id,name', 'contact:id,name'])
            ->when($request->boolean('open'), fn ($q) => $q->whereHas('status', fn ($s) => $s->where('is_open', true)))
            ->orderByDesc('updated_at')->get();
        $events = $this->eventsByTicket($tickets);
        return response()->json(['data' => $tickets->map(fn ($t) =>
            HelpDeskPortalPresenter::ticket($t, $this->sla->clientSummary($t, $events->get($t->id) ?? collect())))]);
    }

    public function showTicket(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        $u = $request->user();
        $scope = $this->access->clientViewScope($u);
        abort_if($scope === 'none', 403, 'Seu perfil de acesso não permite visualizar chamados.');
        abort_if($scope === 'own' && (int) $ticket->requester_user_id !== (int) $u->id, 404);
        if ($scope === 'department') {
            $dept = $u->helpdesk_department_id;
            $reqDept = $ticket->requester_user_id
                ? optional(\App\Models\User::find($ticket->requester_user_id))->helpdesk_department_id : null;
            $sameDept = $dept && $reqDept && (int) $dept === (int) $reqDept;
            abort_unless($sameDept || (int) $ticket->requester_user_id === (int) $u->id, 404);
        }
        $ticket->load(['status:id,key,label,color,is_open,is_resolved,is_terminal,sla_paused', 'service:id,name', 'assignee:id,name', 'category:id,name', 'justification:id,name', 'tags:id,name', 'customer:id,name', 'team:id,name', 'contact:id,name', 'requester:id,name', 'previousTicket:id,ticket_number,customer_id', 'continuations:id,ticket_number,customer_id,previous_ticket_id']);
        $events   = $ticket->events()->where('event_type', 'status_changed')->orderBy('created_at')->get(['from_value', 'to_value', 'created_at']);
        $comments = $ticket->comments()->where('visibility', 'customer')->with('author:id,name')->orderBy('created_at')->get();
        $atts     = $this->publicAttachments($ticket);
        $agentHours = round((float) $ticket->timesheets()->whereNull('deleted_at')->sum('effort_minutes') / 60, 2);
        // Campos visíveis ao cliente (perfil). Legados default visível; novos default oculto.
        $view = [
            'urgency'       => $this->access->clientViewField($u, 'urgency', true),
            'status'        => $this->access->clientViewField($u, 'status', true),
            'sla_due'       => $this->access->clientViewField($u, 'sla_due', true),
            'subject'       => $this->access->clientViewField($u, 'subject', true),
            // Detalhes do chamado — VISÍVEIS por padrão (o cliente vê os dados do próprio chamado).
            'customer'      => $this->access->clientViewField($u, 'customer', true),
            'requester'     => $this->access->clientViewField($u, 'requester', true),
            'agent'         => $this->access->clientViewField($u, 'agent', true),
            'team'          => $this->access->clientViewField($u, 'team', true),
            'category'      => $this->access->clientViewField($u, 'category', true),
            'service'       => $this->access->clientViewField($u, 'service', true),
            'level'         => $this->access->clientViewField($u, 'level', true),
            'reopens'       => $this->access->clientViewField($u, 'reopens', true),
            'cc'            => $this->access->clientViewField($u, 'cc', true),
            // Extras opt-in (mais internos).
            'justification' => $this->access->clientViewField($u, 'justification', false),
            'agent_times'   => $this->access->clientViewField($u, 'agent_times', false),
            'tags'          => $this->access->clientViewField($u, 'tags', false),
            'sla_first'     => $this->access->clientViewField($u, 'sla_first', false),
        ];
        return response()->json(['data' => HelpDeskPortalPresenter::ticketDetail(
            $ticket, $this->sla->clientSummary($ticket, $events), $comments, $atts, $u->id, $view, $agentHours)]);
    }

    public function openTicket(Request $request): JsonResponse
    {
        $cid = $this->customerId($request);
        abort_unless($this->access->clientCanOpen($request->user()), 403, 'Seu perfil de acesso não permite abrir chamados.');
        $v = $request->validate([
            'subject'             => 'required|string|max:200',
            'description'         => 'nullable|string',
            'category_id'         => 'nullable|exists:helpdesk_categories,id',
            'priority'            => 'nullable|in:' . implode(',', HelpDeskTicket::PRIORITIES),
            'contract_id'         => 'nullable|exists:contracts,id',
            'project_id'          => 'nullable|exists:projects,id',
            'customer_contact_id' => 'nullable|exists:customer_contacts,id',
        ]);

        // Perfil de acesso: ignora campos que o cliente não pode informar na abertura.
        $u = $request->user();
        if (!$this->access->informAllowed($u, 'category')) unset($v['category_id']);
        if (!$this->access->informAllowed($u, 'urgency'))  unset($v['priority']);

        if (!empty($v['customer_contact_id'])) {
            abort_unless(\App\Models\CustomerContact::where('id', $v['customer_contact_id'])->where('customer_id', $cid)->exists(), 422, 'Contato não pertence à sua empresa.');
        }
        if (!empty($v['contract_id'])) {
            abort_unless(\App\Models\Contract::where('id', $v['contract_id'])->where('customer_id', $cid)->exists(), 422, 'Contrato não pertence à sua empresa.');
        }

        $ticket = HelpDeskTicket::create(array_merge($v, [
            'customer_id'       => $cid,
            'priority'          => $v['priority'] ?? 'normal',
            'channel'           => 'portal',
            'status_id'         => optional(HelpDeskStatus::default())->id,
            'requester_user_id' => $request->user()->id,
            'created_by_id'     => $request->user()->id,
            'last_activity_at'  => now(),
        ]));
        // Número no formato CONFIGURADO (prefixo + dígitos + sequência) — não hardcoded HD-######.
        $ticket->update(['ticket_number' => \App\Services\HelpDeskTicketNumber::next()]);
        $this->sla->apply($ticket);
        HelpDeskTicketEvent::log($ticket->id, 'created', ['to_value' => $ticket->subject, 'meta' => ['via' => 'portal']]);

        $ticket->load('status:id,key,label,color,is_open,sla_paused');
        return response()->json(['data' => HelpDeskPortalPresenter::ticket($ticket, $this->sla->clientSummary($ticket))], 201);
    }

    /** Cliente responde ao próprio chamado (sempre visível ao cliente). Anexos vão JUNTO da interação. */
    public function addComment(Request $request, HelpDeskTicket $ticket, AttachmentService $svc): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        $v = $request->validate([
            'body'    => 'required_without:files|nullable|string',
            'files'   => 'nullable|array',
            'files.*' => 'file|max:25600',
        ]);
        $comment = $ticket->comments()->create([
            'author_user_id' => $request->user()->id,
            'body'           => $v['body'] ?? '',
            'visibility'     => 'customer',
            'channel'        => 'portal',
        ]);
        // Anexos/prints DA INTERAÇÃO (entity_type do COMENTÁRIO, não solto no chamado).
        foreach ((array) $request->file('files', []) as $file) {
            $svc->store($request->user(), [
                'entity_type' => 'HELPDESK_TICKET_COMMENT', 'entity_id' => $comment->id,
                'category'    => str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : 'attachment',
                'file'        => $file, 'visibility' => 'customer',
            ], $request);
        }
        $ticket->update(['last_activity_at' => now()]);
        HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $comment->id, 'via' => 'portal']]);
        return response()->json(['data' => HelpDeskPortalPresenter::comment($comment->fresh()->load('author:id,name'), $request->user()->id)], 201);
    }

    /** Download de anexo de uma INTERAÇÃO (comentário) do chamado do cliente. */
    public function downloadCommentAttachment(Request $request, HelpDeskTicket $ticket, HelpDeskTicketComment $comment, Attachment $attachment, AttachmentService $svc)
    {
        $this->ownTicket($request, $ticket);
        abort_unless((int) $comment->ticket_id === $ticket->id, 404);
        abort_unless($attachment->entity_type === 'HELPDESK_TICKET_COMMENT' && (int) $attachment->entity_id === $comment->id && $attachment->visibility === 'customer', 404);
        return $svc->downloadStream($attachment, $request->user(), $request);
    }

    /** Cliente ACEITA a solução → chamado é ENCERRADO (fechado). Só se estiver resolvido. */
    public function accept(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        abort_unless(optional($ticket->status)->is_resolved, 422, 'O chamado não está resolvido.');
        $fechado = HelpDeskStatus::where('key', 'fechado')->first();
        abort_unless($fechado, 500, 'Status "fechado" não configurado.');
        $old = $ticket->status;
        $ticket->status_id = $fechado->id;
        if (!$ticket->closed_at) $ticket->closed_at = now();
        $ticket->last_activity_at = now();
        $this->sla->computeBreaches($ticket);
        $ticket->save();
        HelpDeskTicketEvent::log($ticket->id, 'closed', ['to_value' => $fechado->label, 'meta' => ['via' => 'portal', 'aceite_cliente' => true]]);
        HelpDeskTicketEvent::log($ticket->id, 'status_changed', ['field' => 'status', 'from_value' => $old?->key, 'to_value' => $fechado->key, 'meta' => ['via' => 'portal']]);
        // Interação de encerramento (quem encerrou) — mesmo conceito do aceite por e-mail.
        $closerName = trim((string) optional($request->user())->name) ?: 'cliente';
        $c = $ticket->comments()->create([
            'author_user_id' => $request->user()->id,
            'body'           => '🔒 Chamado encerrado por ' . $closerName . '.',
            'visibility'     => 'internal', 'channel' => 'portal', 'is_system' => true,
        ]);
        HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $c->id, 'via' => 'close_portal']]);
        // Gatilho de encerramento (e-mail "Chamado encerrado" com histórico/link).
        try {
            $ctx = app(\App\Services\CompanyContext::class); $ctx->set($ticket->company_id);
            try { \App\Services\HelpDeskTriggerEngine::queue('status_changed', $ticket->fresh(), ['via' => 'portal_aceite']); }
            finally { $ctx->forget(); }
        } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('HelpDesk: gatilho de aceite (portal) falhou: ' . $e->getMessage()); }
        return response()->json(['data' => ['ok' => true]]);
    }

    /** Cliente RECUSA a solução → volta para "Em atendimento" + registra o motivo como interação. */
    public function reject(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        abort_unless(optional($ticket->status)->is_resolved, 422, 'O chamado não está resolvido.');
        $v = $request->validate(['reason' => 'required|string|max:2000']);
        $em = HelpDeskStatus::where('key', 'em_andamento')->first();
        abort_unless($em, 500, 'Status "em andamento" não configurado.');
        $old = $ticket->status;
        $ticket->status_id   = $em->id;
        $ticket->reopened_at = now();
        $ticket->resolved_at = null;
        $ticket->reopen_count = (int) $ticket->reopen_count + 1;
        $ticket->last_activity_at = now();
        $this->sla->computeBreaches($ticket);
        $ticket->save();
        $comment = $ticket->comments()->create([
            'author_user_id'    => $request->user()->id,
            'author_contact_id' => $ticket->customer_contact_id, // quem recusou (contato do cliente)
            'body'              => 'Solução recusada pelo cliente: ' . $v['reason'],
            'visibility'        => 'customer',
            'channel'           => 'portal',
            'form_kind'         => 'rejection', // card vermelho "Solução recusada" no timeline
        ]);
        HelpDeskTicketEvent::log($ticket->id, 'reopened', ['to_value' => $em->label, 'meta' => ['via' => 'portal', 'motivo' => $v['reason']]]);
        HelpDeskTicketEvent::log($ticket->id, 'status_changed', ['field' => 'status', 'from_value' => $old?->key, 'to_value' => $em->key, 'meta' => ['via' => 'portal']]);
        HelpDeskTicketEvent::log($ticket->id, 'comment', ['meta' => ['comment_id' => $comment->id, 'via' => 'portal']]);
        // Aviso DETERMINÍSTICO de recusa (equipe + cliente), threadado — igual ao aceite/recusa por e-mail.
        try {
            \App\Jobs\SendHelpDeskRejectionEmailsJob::dispatch($ticket->id, $v['reason'], $comment->id)->onConnection(config('queue.helpdesk_email_connection'))->onQueue('emails');
        } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('HelpDesk: dispatch do e-mail de recusa (portal) falhou: ' . $e->getMessage()); }
        return response()->json(['data' => ['ok' => true]]);
    }

    // ── Anexos do chamado pelo cliente (R5) — Attachment Engine global ────────
    public function attachments(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        return response()->json(['data' => $this->publicAttachments($ticket)
            ->map(fn (Attachment $a) => HelpDeskPortalPresenter::attachment($a, $ticket->id))->values()]);
    }

    public function uploadAttachment(Request $request, HelpDeskTicket $ticket, AttachmentService $svc): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        $request->validate(['file' => 'required|file|max:25600']);
        $att = $svc->store($request->user(), [
            'entity_type' => 'HELPDESK_TICKET', 'entity_id' => $ticket->id,
            'category'    => 'attachment', 'file' => $request->file('file'),
            'visibility'  => 'customer', // anexo do cliente nasce visível ao cliente
        ], $request);
        $ticket->update(['last_activity_at' => now()]);
        HelpDeskTicketEvent::log($ticket->id, 'attachment_added', ['meta' => ['attachment_id' => $att->id ?? null, 'via' => 'portal']]);
        return response()->json(['data' => HelpDeskPortalPresenter::attachment($att, $ticket->id)], 201);
    }

    public function downloadAttachment(Request $request, HelpDeskTicket $ticket, Attachment $attachment, AttachmentService $svc)
    {
        $this->ownTicket($request, $ticket);
        abort_unless($attachment->entity_type === 'HELPDESK_TICKET' && (int) $attachment->entity_id === $ticket->id && $attachment->visibility === 'customer', 404);
        return $svc->downloadStream($attachment, $request->user(), $request);
    }

    public function deleteAttachment(Request $request, HelpDeskTicket $ticket, Attachment $attachment, AttachmentService $svc): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        abort_unless($attachment->entity_type === 'HELPDESK_TICKET' && (int) $attachment->entity_id === $ticket->id && $attachment->visibility === 'customer', 404);
        $svc->softDeleteOwn($attachment, $request->user(), $request); // só o próprio upload (não remove doc do atendente)
        return response()->json(null, 204);
    }

    // ── Base de Conhecimento (somente publicado e visível ao cliente) ─────────
    private function kbVisible()
    {
        return HelpDeskKbArticle::query()->where('status', 'published')->where('visibility', 'customer');
    }

    public function kbIndex(Request $request): JsonResponse
    {
        $arts = $this->kbVisible()
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) =>
                $w->where('title', 'ilike', '%' . $request->search . '%')->orWhere('body', 'ilike', '%' . $request->search . '%')))
            ->orderByDesc('pinned')->orderByDesc('published_at')->get();
        return response()->json(['data' => $arts->map(fn (HelpDeskKbArticle $a) => HelpDeskPortalPresenter::kbArticle($a))]);
    }

    public function kbShow(HelpDeskKbArticle $article): JsonResponse
    {
        abort_unless($article->status === 'published' && $article->visibility === 'customer', 404);
        $article->increment('views_count');
        $article->load('category:id,name');
        return response()->json(['data' => HelpDeskPortalPresenter::kbArticle($article, true)]);
    }

    public function kbFeedback(Request $request, HelpDeskKbArticle $article): JsonResponse
    {
        abort_unless($article->status === 'published' && $article->visibility === 'customer', 404);
        $v = $request->validate(['helpful' => 'required|boolean', 'comment' => 'nullable|string|max:500']);
        $article->feedback()->create([
            'user_id' => $request->user()?->id,
            'helpful' => $v['helpful'],
            'comment' => $v['comment'] ?? null,
            'ip'      => $request->ip(),
        ]);
        $article->increment($v['helpful'] ? 'helpful_count' : 'not_helpful_count');
        return response()->json(['data' => ['ok' => true]]);
    }
}
