<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\HelpDeskCategory;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTeam;
use App\Models\HelpDeskTrigger;
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

    /** Relações do DETALHE do chamado (colunas enxutas) — compartilhadas por with()/load(). */
    private function detailRels(): array
    {
        return [
            'customer:id,name', 'contact:id,name,email', 'requester:id,name',
            'category:id,name,color', 'status:id,key,label,color,is_open,is_resolved,is_terminal',
            'assignee:id,name', 'team:id,name',
            'contract:id,categoria,helpdesk_integration_enabled', 'project:id,name',
            'service:id,name,code', 'justification:id,name,status_id',
            'continuations:id,ticket_number,previous_ticket_id', // p/ continuation_ticket no payload
        ];
    }

    /**
     * Enriquece o payload do ticket (show E detail) com os flags de PERMISSÃO do usuário +
     * solicitante resolvido + continuação. Fonte ÚNICA — o /detail (perf) e o /show precisam
     * devolver EXATAMENTE os mesmos flags, senão o FE perde o menu de gestão e o encerrar/cancelar.
     */
    private function enrichTicketFlags(HelpDeskTicket $ticket, array $data, ?\App\Models\User $user): array
    {
        $data['can_edit_description'] = $this->access->canEditActions($user);
        $data['can_merge']      = $this->access->canMerge($user);
        $data['can_delete']     = $this->access->canDelete($user);
        $data['can_print']      = $this->access->canPrint($user);
        $data['can_view_sla']   = $this->access->canViewSla($user);
        $data['can_clone']      = $this->access->canClone($user);
        $data['can_send_email'] = $this->access->canSendEmail($user);
        $data['can_reopen']     = $this->access->canReopen($user);
        $data['can_close']      = $this->access->canClose($user);
        $data['solicitante']    = ['name' => $ticket->solicitanteName(), 'email' => $ticket->solicitanteEmail()];
        $data['continuation_ticket'] = optional($ticket->relationLoaded('continuations')
            ? $ticket->continuations->sortByDesc('id')->first()
            : $ticket->continuations()->orderByDesc('id')->first())->only(['id', 'ticket_number']) ?: null;
        return $data;
    }

    private function withRels($q)
    {
        return $q->with($this->detailRels());
    }

    /**
     * Relações MÍNIMAS da LISTAGEM/fila (index). O card só usa customer, assignee, status e o
     * solicitante (contact/requester p/ montar o nome). Carregar as 11 relações do detalhe aqui
     * (contract/project/service/justification/category/team) multiplica a serialização por 500
     * tickets sem ninguém ler. O detalhe (show/store/update) continua usando withRels completo.
     */
    private function withListRels($q)
    {
        return $q->with([
            'customer:id,name', 'contact:id,name', 'requester:id,name',
            'status:id,key,label,color,is_open,is_resolved,is_terminal', 'assignee:id,name',
        ]);
    }

    private function decorate(HelpDeskTicket $t, ?\Illuminate\Support\Collection $events = null, $lastAgentAt = null, ?\App\Services\BusinessCalendarService $cal = null, bool $lean = false): array
    {
        // Solicitante resolvido SEM query extra (usa relações já eager-loaded) — p/ o card da fila.
        $solicitante = optional($t->contact)->name ?: optional($t->requester)->name ?: $t->requester_name;
        // Dias ÚTEIS sem interação da EQUIPE: referência = última interação de agente OU abertura.
        // NÃO conta quando a bola NÃO está com a equipe: encerrados (fechado/cancelado), entregues
        // (resolvido/solução com GMUD), aguardando cliente/terceiros, ou agendado (reunião marcada).
        $semIntExcl = ['fechado', 'cancelado', 'resolvido', 'solucao_gmud', 'aguardando_cliente', 'pendente_terceiros', 'reuniao_agendada'];
        $foraDaFilaEquipe = in_array(optional($t->status)->key, $semIntExcl, true)
            || ($t->scheduled_until && \Illuminate\Support\Carbon::parse($t->scheduled_until)->isFuture());
        $ref = $lastAgentAt ? \Illuminate\Support\Carbon::parse($lastAgentAt) : $t->created_at;
        $diasSemInteracao = (!$foraDaFilaEquipe && $ref && $cal) ? max(0, $cal->businessDaysBetween($ref, now()) - 1) : 0;

        return array_merge($t->toArray(), [
            // Na LISTA (lean) o card só lê os flags do SLA — listSummary pula a serialização de datas.
            'sla'                    => $lean ? $this->sla->listSummary($t, $events) : $this->sla->summary($t, $events),
            'solicitante_nome'       => $solicitante,
            'last_agent_activity_at' => $lastAgentAt ? \Illuminate\Support\Carbon::parse($lastAgentAt)->toIso8601String() : null,
            'dias_sem_interacao'     => $diasSemInteracao, // dias úteis desde a última interação da equipe
        ]);
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

    /**
     * Última interação DA EQUIPE por ticket, em UMA query (anti-N+1). Interação de equipe = comment
     * com autor sendo um usuário NÃO-cliente (agente) e não-sistema. Resposta do cliente (portal/e-mail)
     * NÃO conta. Retorna mapa ticket_id => timestamp da última interação de agente.
     */
    private function lastAgentCommentByTicket(\Illuminate\Support\Collection $tickets): \Illuminate\Support\Collection
    {
        if ($tickets->isEmpty()) return collect();
        return HelpDeskTicketComment::query()
            ->join('users', 'users.id', '=', 'helpdesk_ticket_comments.author_user_id')
            ->whereIn('helpdesk_ticket_comments.ticket_id', $tickets->pluck('id'))
            ->where('users.type', '<>', 'cliente')
            ->where('helpdesk_ticket_comments.is_system', false)
            ->whereNull('helpdesk_ticket_comments.deleted_at')
            ->groupBy('helpdesk_ticket_comments.ticket_id')
            ->selectRaw('helpdesk_ticket_comments.ticket_id as tid, MAX(helpdesk_ticket_comments.created_at) as last_agent_at')
            ->pluck('last_agent_at', 'tid');
    }

    private function filtered(Request $request)
    {
        $user = $request->user();
        return $this->access->applyViewScope($this->withListRels(HelpDeskTicket::query()), $user) // perfil: escopo de visão
            ->whereNull('merged_into_id') // chamados mesclados somem das listagens (ficam no destino)
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
            // Busca ÚNICA da fila — respeita todos os filtros (roda dentro do filtered()): assunto,
            // descrição, cliente, solicitante/responsável/contato E conteúdo das interações.
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%' . $request->search . '%';
                $q->where(fn ($w) => $w
                    ->where('subject', 'ilike', $s)
                    ->orWhere('description', 'ilike', $s)
                    ->orWhere('requester_name', 'ilike', $s)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', $s))
                    ->orWhereHas('assignee', fn ($a) => $a->where('name', 'ilike', $s))
                    ->orWhereHas('contact', fn ($c) => $c->where('name', 'ilike', $s))
                    ->orWhereHas('requester', fn ($r) => $r->where('name', 'ilike', $s))
                    ->orWhereHas('comments', fn ($cm) => $cm->whereNull('deleted_at')->where('body', 'ilike', $s)));
            })
            // Filtro DEDICADO por número do chamado (campo separado da busca geral).
            ->when($request->filled('ticket'), fn ($q) => $q->where('ticket_number', 'ilike', '%' . $request->ticket . '%'))
            ->when($request->boolean('active'), fn ($q) => $q->whereHas('status', fn ($w) => $w->where('is_terminal', false)->where('is_resolved', false)))
            // ESCALA: filtro de DATA no banco (usa índice created_at) — a fila deixa de carregar "os N
            // mais recentes de toda a história" e passa a varrer só o período pedido.
            ->when($request->filled('created_from'), fn ($q) => $q->where('created_at', '>=', $request->input('created_from')))
            ->when($request->filled('created_to'), fn ($q) => $q->where('created_at', '<=', $request->input('created_to')))
            // Modo FILA (sem período): ativos SEMPRE + encerrados só recentes → conjunto limitado,
            // independente de quantos encerrados existam no histórico. Encerrados antigos ficam no Histórico.
            // Usa status_id (índice) em vez de whereHas (subquery) p/ o planner fazer bitmap-OR de índices.
            ->when($request->boolean('queue'), function ($q) use ($request) {
                $activeIds = HelpDeskStatus::where('is_terminal', false)->where('is_resolved', false)->pluck('id')->all();
                $q->where(fn ($w) => $w
                    ->whereIn('status_id', $activeIds)
                    ->orWhere('updated_at', '>=', now()->subDays((int) $request->input('queue_recent_days', 45))));
            })
            ->orderByDesc('updated_at');
    }

    public function index(Request $request): JsonResponse
    {
        $tickets = $this->filtered($request)->limit((int) $request->input('limit', 200))->get();
        // A lista/kanban NÃO usa o corpo do chamado — ocultar 'description' enxuga muito o payload
        // (o detalhe usa o endpoint show, que mantém tudo).
        $tickets->makeHidden(['description']);
        // Só quem JÁ pausou precisa dos eventos p/ reconstruir a pausa de SLA — a maioria nunca pausou
        // e recebe coleção vazia (pausa por status = 0). Corta a query de eventos e o cálculo por ticket.
        $events = $this->eventsByTicket($tickets->where('sla_ever_paused', true)->values());
        $lastAgent = $this->lastAgentCommentByTicket($tickets);
        $cal = app(\App\Services\BusinessCalendarService::class);
        return response()->json(['data' => $tickets->map(fn ($t) => $this->decorate($t, $events->get($t->id) ?? collect(), $lastAgent->get($t->id), $cal, true))]);
    }

    /**
     * Busca GLOBAL (lupa) — pesquisa QUALQUER chamado por número, assunto, descrição, cliente,
     * solicitante/responsável e CONTEÚDO das interações. Ignora o escopo da fila de propósito: o
     * agente encontra chamados de outros para abrir e assumir. Gated por perfil (canGlobalSearch).
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canGlobalSearch($user), 403, 'Seu perfil de acesso não permite a busca global.');
        $term = trim((string) $request->input('q', ''));
        if (mb_strlen($term) < 2) return response()->json(['data' => []]);

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';

        $tickets = HelpDeskTicket::query()
            ->with(['customer:id,name', 'assignee:id,name', 'status:id,label,color', 'contact:id,name', 'requester:id,name'])
            ->whereNull('merged_into_id')
            ->where(function ($w) use ($like) {
                $w->where('ticket_number', 'ilike', $like)
                  ->orWhere('subject', 'ilike', $like)
                  ->orWhere('description', 'ilike', $like)
                  ->orWhere('requester_name', 'ilike', $like)
                  ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', $like))
                  ->orWhereHas('assignee', fn ($a) => $a->where('name', 'ilike', $like))
                  ->orWhereHas('contact', fn ($c) => $c->where('name', 'ilike', $like))
                  ->orWhereHas('comments', fn ($cm) => $cm->whereNull('deleted_at')->where('body', 'ilike', $like));
            })
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get();

        $data = $tickets->map(function (HelpDeskTicket $t) use ($term) {
            $snippet = null;
            foreach ([$t->subject, $t->description] as $txt) {
                if ($txt && ($s = $this->searchSnippet($txt, $term))) { $snippet = $s; break; }
            }
            if (!$snippet) {
                $c = $t->comments()->whereNull('deleted_at')->where('body', 'ilike', '%' . $term . '%')->latest()->first(['body']);
                if ($c) $snippet = $this->searchSnippet($c->body, $term);
            }
            return [
                'id'            => $t->id,
                'ticket_number' => $t->ticket_number,
                'subject'       => $t->subject,
                'customer'      => optional($t->customer)->name,
                'person'        => $t->requester_name ?: optional($t->contact)->name ?: optional($t->requester)->name ?: optional($t->assignee)->name,
                'assignee'      => optional($t->assignee)->name,
                'status'        => $t->status ? ['label' => $t->status->label, 'color' => $t->status->color] : null,
                'snippet'       => $snippet,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /** Trecho de texto (sem HTML) em volta do termo — para pré-visualizar onde casou. */
    private function searchSnippet(?string $html, string $term): ?string
    {
        if (!$html) return null;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        if ($text === '') return null;
        $pos = mb_stripos($text, $term);
        if ($pos === false) return mb_substr($text, 0, 140);
        $start = max(0, $pos - 40);
        $snippet = mb_substr($text, $start, 160);
        return ($start > 0 ? '…' : '') . $snippet . (mb_strlen($text) > $start + 160 ? '…' : '');
    }

    /**
     * "Detalhes do ticket" — datas, tempo de vida (corrido e útil) e HISTÓRICO com o tempo de
     * PERMANÊNCIA em cada etapa (status), responsável e equipe. Só leitura (agrega dos eventos).
     */
    public function details(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canSee($request->user(), $ticket), 403, 'Seu perfil de acesso não permite ver este chamado.');
        $ticket->loadMissing('status:id,key,label,color', 'assignee:id,name', 'team:id,name');
        $cal = app(\App\Services\BusinessCalendarService::class);

        // Resolvedores (status por id/key; usuários e equipes por id).
        $statuses = HelpDeskStatus::get(['id', 'key', 'label', 'color']);
        $stById = $statuses->keyBy('id'); $stByKey = $statuses->keyBy('key');
        $resolveStatus = function ($v) use ($stById, $stByKey) {
            if ($v === null || $v === '') return null;
            $s = $stById->get((int) $v) ?: $stByKey->get((string) $v);
            return $s ? ['key' => $s->key, 'label' => $s->label, 'color' => $s->color] : ['key' => null, 'label' => (string) $v, 'color' => null];
        };

        $evs = $ticket->events()->orderBy('created_at')->orderBy('id')->get(['event_type', 'from_value', 'to_value', 'created_at']);
        $statusEvs = $evs->whereIn('event_type', ['status', 'status_changed'])->values();
        $assignEvs = $evs->where('event_type', 'assigned')->values();
        $teamEvs   = $evs->where('event_type', 'team_changed')->values();

        // Mapas de nome p/ usuários e equipes citados nos eventos.
        $userIds = $assignEvs->flatMap(fn ($e) => [$e->from_value, $e->to_value])->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $teamIds = $teamEvs->flatMap(fn ($e) => [$e->from_value, $e->to_value])->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $userNames = $userIds ? \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id') : collect();
        $teamNames = $teamIds ? HelpDeskTeam::whereIn('id', $teamIds)->pluck('name', 'id') : collect();

        $opened = $ticket->created_at;
        $endLife = $ticket->resolved_at ?? $ticket->closed_at; // fim do ciclo (null = ainda aberto)
        $lifeEnd = $endLife ?? now();
        $lifeSecs = $opened ? max(0, $lifeEnd->diffInSeconds($opened)) : 0;

        // Constrói segmentos (o que estava valendo, de A até B) com tempo de permanência.
        $seg = function ($label, $meta, $from, $to, $current) use ($cal) {
            $secs = ($from && $to) ? max(0, $to->diffInSeconds($from)) : 0;
            return [
                'label' => $label, 'meta' => $meta,
                'from' => optional($from)->toIso8601String(), 'to' => $current ? null : optional($to)->toIso8601String(),
                'seconds' => $secs, 'human' => self::humanDur($secs),
                'business_days' => ($from && $to) ? $cal->businessDaysBetween($from, $to) : 0,
                'current' => $current,
            ];
        };
        $timeline = function ($events, $initialResolve, $currentValue, $resolver, $labeler) use ($opened, $endLife, $seg) {
            $out = []; $segStart = $opened; $segVal = $initialResolve;
            foreach ($events as $e) {
                $at = $e->created_at;
                $out[] = $seg($labeler($segVal), $segVal, $segStart, $at, false);
                $segVal = $resolver($e->to_value); $segStart = $at;
            }
            $out[] = $seg($labeler($segVal), $segVal, $segStart, $endLife ?? now(), $endLife === null);
            return $out;
        };

        // Status: inicial = from do 1º evento; senão o status atual.
        $statusInit = $statusEvs->isNotEmpty() ? $resolveStatus($statusEvs->first()->from_value) : $resolveStatus(optional($ticket->status)->id);
        $statusHistory = $timeline($statusEvs, $statusInit, null, $resolveStatus, fn ($s) => $s['label'] ?? '—');

        // Responsável: inicial = from do 1º 'assigned'; senão nenhum.
        $nameOf = fn ($v) => $v ? ($userNames[(int) $v] ?? ('Usuário #' . $v)) : 'Não atribuído';
        $assignInit = $assignEvs->isNotEmpty() ? $nameOf($assignEvs->first()->from_value) : $nameOf(optional($ticket->assignee)->id);
        $assigneeHistory = $timeline($assignEvs, $assignInit, null, fn ($v) => $nameOf($v), fn ($n) => $n);

        // Equipe.
        $teamOf = fn ($v) => $v ? ($teamNames[(int) $v] ?? ('Equipe #' . $v)) : 'Sem equipe';
        $teamInit = $teamEvs->isNotEmpty() ? $teamOf($teamEvs->first()->from_value) : $teamOf(optional($ticket->team)->id);
        $teamHistory = $timeline($teamEvs, $teamInit, null, fn ($v) => $teamOf($v), fn ($n) => $n);

        return response()->json(['data' => [
            'dates' => [
                'opened_at'    => optional($opened)->toIso8601String(),
                'due_at'       => optional($ticket->resolution_due_at)->toIso8601String(),
                'first_due_at' => optional($ticket->first_response_due_at)->toIso8601String(),
                'resolved_at'  => optional($ticket->resolved_at)->toIso8601String(),
                'closed_at'    => optional($ticket->closed_at)->toIso8601String(),
            ],
            'life' => [
                'open'          => $endLife === null,
                'seconds'       => $lifeSecs,
                'human'         => self::humanDur($lifeSecs),
                'business_days' => $opened ? $cal->businessDaysBetween($opened, $lifeEnd) : 0,
            ],
            'status_history'   => $statusHistory,
            'assignee_history' => $assigneeHistory,
            'team_history'     => $teamHistory,
            'reopen_count'     => (int) $ticket->reopen_count,
        ]]);
    }

    /**
     * Detalhes do SLA aplicado ao chamado: política, calendário, regra por prioridade,
     * prazos efetivos (descontando pausas/agendamentos) com situação/severidade, e alertas
     * automáticos configurados. Só leitura — reaproveita HelpDeskSlaService::summary.
     */
    public function sla(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canSee($request->user(), $ticket), 403, 'Seu perfil de acesso não permite ver este chamado.');
        $ticket->loadMissing('status:id,key,label,color', 'category:id,name,sla_policy_id', 'slaPolicy');

        $policy = $ticket->sla_policy_id ? $ticket->slaPolicy : null;
        $target = $policy?->targetFor($ticket->priority);
        if ($target && $target->enabled === false) $target = null;

        // Origem da política (por que este SLA foi aplicado ao chamado).
        $source = null;
        if ($policy) {
            if ($policy->customer_id && $policy->customer_id === $ticket->customer_id) $source = 'Cliente';
            elseif ($policy->contract_id) $source = 'Contrato';
            elseif ($ticket->category && $ticket->category->sla_policy_id === $policy->id) $source = 'Categoria';
            elseif ($policy->is_default) $source = 'Padrão';
            else $source = 'Definido no chamado';
        }

        // Calendário: horas úteis (janelas por dia) x corridas (24×7).
        $wins = $policy?->windowsByWeekday() ?? [];
        $active = array_filter($wins, fn ($d) => !empty($d));
        $calendarMode = empty($active) ? 'corrido' : 'util';
        $hoursLabel = 'Horas corridas (24×7)';
        if (!empty($active)) {
            $DOW = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
            $days = array_keys($active); sort($days);
            $dayLabel = (count($days) === 5 && $days === [1, 2, 3, 4, 5]) ? 'Seg–Sex'
                : implode(', ', array_map(fn ($d) => $DOW[$d] ?? $d, $days));
            $mins = []; foreach ($active as $ranges) foreach ($ranges as $r) { $mins[] = $r[0]; $mins[] = $r[1]; }
            $toHHMM = fn ($m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            $hoursLabel = $days ? ($dayLabel . ' · ' . $toHHMM(min($mins)) . '–' . $toHHMM(max($mins))) : $hoursLabel;
        }

        // Resumo de prazos EFETIVOS (já desconta pausa/agendamento).
        $s = $this->sla->summary($ticket);
        $now = now();

        // Constrói uma "meta" de SLA (1ª resposta ou resolução) com situação + severidade.
        $meta = function (string $key, string $label, ?int $targetMinutes, ?string $dueIso, bool $done, ?string $doneAt, bool $overdue, bool $breached, ?int $minsLeft) use ($now) {
            $applies = $targetMinutes !== null && $dueIso !== null;
            $severity = 'none'; $situacao = 'Sem SLA';
            if ($applies) {
                if ($done) { $severity = 'ok'; $situacao = 'Cumprido'; }
                elseif ($overdue || $breached) { $severity = 'danger'; $situacao = 'Vencido'; }
                else {
                    // Amarelo quando faltam ≤60min OU ≤20% do prazo total.
                    $threshold = $targetMinutes ? min(60, max(15, (int) round($targetMinutes * 0.2))) : 60;
                    if ($minsLeft !== null && $minsLeft <= $threshold) { $severity = 'warning'; $situacao = 'Vence em breve'; }
                    else { $severity = 'ok'; $situacao = 'No prazo'; }
                }
            }
            // Progresso 0–1 do consumo do prazo (para a barra), quando aplicável.
            $progress = null;
            if ($applies && $targetMinutes > 0) {
                if ($done) $progress = 1.0;
                elseif ($minsLeft !== null) $progress = max(0.0, min(1.0, ($targetMinutes - $minsLeft) / $targetMinutes));
            }
            return [
                'key' => $key, 'label' => $label, 'applies' => $applies,
                'target_minutes' => $targetMinutes, 'due_at' => $dueIso,
                'done' => $done, 'done_at' => $doneAt, 'breached' => $breached,
                'minutes_left' => $applies && !$done ? $minsLeft : null,
                'situacao' => $situacao, 'severity' => $severity, 'progress' => $progress,
            ];
        };

        $metas = [
            $meta('first', '1ª resposta', $target?->first_response_minutes,
                $s['first_response_due_at'], (bool) $s['first_responded_at'], $s['first_responded_at'],
                (bool) $s['first_response_overdue'], (bool) $s['first_response_breached'], $s['first_response_minutes_left']),
            $meta('resolution', 'Resolução', $target?->resolution_minutes,
                $s['resolution_due_at'], (bool) $s['resolved_at'], $s['resolved_at'],
                (bool) $s['resolution_overdue'], (bool) $s['resolution_breached'], $s['resolution_minutes_left']),
        ];

        // Alertas automáticos configurados relacionados a SLA (dado real: gatilhos ativos).
        $alerts = HelpDeskTrigger::where('enabled', true)
            ->get(['id', 'name', 'event', 'conditions'])
            ->filter(function ($t) {
                $c = is_array($t->conditions) ? $t->conditions : (json_decode($t->conditions, true) ?: []);
                return $t->event === 'idle_in_status' || array_key_exists('resolution_breached', $c) || array_key_exists('idle_hours', $c);
            })
            ->map(fn ($t) => ['name' => $t->name, 'event' => $t->event])
            ->values();

        return response()->json(['data' => [
            'policy' => $policy ? [
                'name'        => $policy->name,
                'description' => $policy->description,
                'source'      => $source,
                'timezone'    => $policy->slaTimezone(),
                'calendar_mode' => $calendarMode,
                'hours_label' => $hoursLabel,
                'national_holidays' => (bool) ($policy->use_national_holidays ?? true),
                'holidays_count'    => count($policy->holidayDates()),
            ] : null,
            'target' => $target ? [
                'name'                   => $target->name,
                'priority'               => $target->priority,
                'first_response_minutes' => $target->first_response_minutes,
                'resolution_minutes'     => $target->resolution_minutes,
            ] : null,
            'priority' => $ticket->priority,
            'metas'    => $metas,
            'paused'   => (bool) $s['paused'],
            'scheduled' => (bool) $s['scheduled'],
            'scheduled_until'   => $s['scheduled_until'],
            'scheduled_all_day' => (bool) $s['scheduled_all_day'],
            'generated_at' => $now->toIso8601String(),
            'alerts'   => $alerts,
        ]]);
    }

    /**
     * Relatório de Serviço (PDF) — documento formal do atendimento: capa, dados do chamado,
     * SLA, descrição e histórico de interações PÚBLICAS (client-safe) + apontamentos por consultor.
     * Notas internas ficam de fora (deliverable ao cliente). Gate: mesma permissão de impressão.
     */
    public function report(Request $request, HelpDeskTicket $ticket)
    {
        abort_unless($this->access->canSee($request->user(), $ticket), 403, 'Seu perfil de acesso não permite ver este chamado.');
        abort_unless($this->access->canPrint($request->user()), 403, 'Seu perfil de acesso não permite gerar o relatório.');
        $ticket->loadMissing('status:id,key,label,color', 'category:id,name', 'service:id,name', 'customer:id,name', 'contact:id,name', 'requester:id,name', 'assignee:id,name', 'team:id,name');

        $PRIO = ['baixa' => 'Baixa', 'normal' => 'Normal', 'alta' => 'Alta', 'urgente' => 'Urgente'];
        $CH   = ['portal' => 'Portal', 'email' => 'E-mail', 'telefone' => 'Telefone', 'interno' => 'Interno', 'movidesk' => 'Movidesk'];
        $fmt  = fn ($d) => $d ? \Carbon\Carbon::parse($d)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '—';
        // Corpo FIEL ao chamado: se já é HTML (inclui a assinatura renderizada), mantém o HTML
        // para o dompdf renderizar igual ao chamado; se é texto puro, preserva quebras de linha.
        $bodyHtml = function (?string $html) {
            $html = (string) $html;
            if (trim($html) === '') return '';
            if (strip_tags($html) === $html) {
                // Texto puro: remove emojis (tofu no DejaVu), escapa e converte quebras.
                $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}]/u', '', $html);
                return nl2br(e(trim($t)));
            }
            return $html; // já é HTML (mensagem + assinatura) — renderiza como está
        };

        $withApontamentos = $request->boolean('apontamentos');
        $hhmm = fn (?string $t) => $t ? substr($t, 0, 5) : null;

        // TODAS as interações do chamado (cliente + notas internas), sem eventos de sistema —
        // fiel ao que aparece no chamado.
        // Mais recente primeiro (igual ao chamado).
        $comments = $ticket->comments()->with(['author:id,name,type', 'contact:id,name'])
            ->where('is_system', false)
            ->whereNull('deleted_at')->orderByDesc('created_at')->orderByDesc('id')->get();
        $interactions = $comments->map(function ($c) use ($bodyHtml, $fmt, $withApontamentos) {
            $isAgent = $c->author && in_array($c->author->type, ['admin', 'coordenador', 'consultor'], true);
            $who = $c->author?->name ?: $c->contact?->name ?: 'Cliente';
            return [
                'who' => $who, 'is_agent' => $isAgent, 'internal' => $c->visibility === 'internal',
                'solution' => (bool) $c->solution,
                'when' => $fmt($c->created_at), 'body' => $bodyHtml($c->body),
                // Horas por interação só quando o relatório inclui apontamentos.
                'effort' => ($withApontamentos && $c->effort_minutes) ? self::humanDur((int) $c->effort_minutes * 60) : null,
            ];
        })->values()->all();

        // Apontamentos (uma linha por interação com tempo) — para a tabela final + totais.
        $apontRows = $ticket->comments()->whereNull('deleted_at')->where('effort_minutes', '>', 0)
            ->with('author:id,name')->orderBy('worked_date')->orderBy('start_time')->orderBy('created_at')->get();
        $apontamentos = []; $hoursBy = []; $totalMin = 0; $byUserMin = [];
        foreach ($apontRows as $c) {
            $mins = (int) $c->effort_minutes; $totalMin += $mins;
            $nome = $c->author?->name ?? 'Consultor';
            $byUserMin[$nome] = ($byUserMin[$nome] ?? 0) + $mins;
            $apontamentos[] = [
                'date'       => optional($c->worked_date ?: $c->created_at)->format('d/m/Y'),
                'consultant' => $nome,
                'interval'   => ($c->start_time || $c->end_time) ? (($hhmm($c->start_time) ?? '—') . '–' . ($hhmm($c->end_time) ?? '—')) : '—',
                'duration'   => self::humanDur($mins * 60),
                'billable'   => !$c->no_charge,
            ];
        }
        foreach ($byUserMin as $nome => $mins) $hoursBy[] = ['name' => $nome, 'h' => self::humanDur($mins * 60)];

        // SLA (situação de resolução).
        $slaData = null;
        if ($ticket->sla_policy_id) {
            $s = $this->sla->summary($ticket);
            $ticket->loadMissing('slaPolicy:id,name');
            $resColor = '#111827'; $resTxt = $fmt($s['resolution_due_at']);
            if ($s['resolved_at']) { $resColor = '#16a34a'; $resTxt = 'Resolvido em ' . $fmt($s['resolved_at']); }
            elseif ($s['resolution_overdue'] || $s['resolution_breached']) { $resColor = '#dc2626'; $resTxt = 'Vencido — prazo era ' . $fmt($s['resolution_due_at']); }
            $slaData = ['policy' => $ticket->slaPolicy?->name ?? '—', 'res' => $resTxt, 'res_color' => $resColor];
        }

        $opened = $ticket->created_at;
        $endLife = $ticket->resolved_at ?? $ticket->closed_at;
        $lifeSecs = $opened ? max(0, ($endLife ?? now())->diffInSeconds($opened)) : 0;

        $st = $ticket->status;
        $logoPath = public_path('logo-erpserv.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;

        $data = [
            'with_apontamentos' => $withApontamentos,
            'apontamentos' => $apontamentos,
            'brand'        => '#7c3aed',
            'logo'         => $logo,
            'ticket_number' => $ticket->ticket_number ?: ('#' . $ticket->id),
            'generated_at' => now()->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            'subject'      => $ticket->subject ?: '—',
            'status'       => $st?->label ?? '—',
            'status_bg'    => ($st?->color ?: '#6b7280') . '22',
            'status_fg'    => $st?->color ?: '#374151',
            'priority'     => $PRIO[$ticket->priority] ?? ucfirst((string) $ticket->priority),
            'channel'      => $CH[$ticket->channel] ?? ucfirst((string) $ticket->channel),
            'category'     => $ticket->category?->name ?? ($ticket->service?->name ?? '—'),
            'customer'     => $ticket->customer?->name ?? '—',
            'requester'    => $ticket->contact?->name ?: ($ticket->requester?->name ?: ($ticket->requester_name ?: '—')),
            'assignee'     => $ticket->assignee?->name ?? 'Não atribuído',
            'team'         => $ticket->team?->name ?? '—',
            'opened_at'    => $fmt($opened),
            'first_at'     => $fmt($ticket->first_responded_at),
            'resolved_at'  => $fmt($ticket->resolved_at),
            'life'         => self::humanDur($lifeSecs),
            'description'  => $bodyHtml($ticket->description),
            'sla'          => $slaData,
            'interactions' => $interactions,
            'hours'        => ['total' => $totalMin ? self::humanDur($totalMin * 60) : null, 'by' => $hoursBy],
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.helpdesk-service-report', $data)
            ->setPaper('a4', 'portrait')->setOption(['defaultMediaType' => 'print', 'isRemoteEnabled' => true]);
        $fname = 'relatorio-servico-' . preg_replace('/[^A-Za-z0-9_-]/', '', $data['ticket_number']) . '.pdf';
        return $pdf->stream($fname);
    }

    /**
     * Listagem COMPLETA de apontamentos do chamado: cada interação com horas apontadas
     * (data, intervalo, duração, consultor, cobrável, vínculo com o apontamento na tela
     * de Apontamentos). Total geral + por consultor. Só leitura.
     */
    public function apontamentos(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canSee($request->user(), $ticket), 403, 'Seu perfil de acesso não permite ver este chamado.');

        // Escopo por perfil: todos os apontamentos ou só os do usuário logado.
        $scope = $this->access->apontamentosScope($request->user());
        $rows = $ticket->comments()->whereNull('deleted_at')->where('effort_minutes', '>', 0)
            ->when($scope === 'own', fn ($q) => $q->where('author_user_id', $request->user()?->id))
            ->with('author:id,name')->orderBy('worked_date')->orderBy('start_time')->orderBy('created_at')->get();

        $hhmm = fn (?string $t) => $t ? substr($t, 0, 5) : null;
        $items = $rows->map(function ($c) use ($hhmm) {
            $mins = (int) $c->effort_minutes;
            return [
                'id'            => $c->id,
                'date'          => optional($c->worked_date ?: $c->created_at)->toDateString(),
                'start'         => $hhmm($c->start_time),
                'end'           => $hhmm($c->end_time),
                'minutes'       => $mins,
                'duration'      => self::humanDur($mins * 60),
                'consultant'    => $c->author?->name ?? 'Consultor',
                'consultant_id' => $c->author_user_id,
                'no_charge'     => (bool) $c->no_charge,
                'solution'      => (bool) $c->solution,
                'visibility'    => $c->visibility,
                'timesheet_id'  => $c->timesheet_id,
            ];
        })->values();

        $totalMin = (int) $rows->sum('effort_minutes');
        $chargeMin = (int) $rows->where('no_charge', false)->sum('effort_minutes');
        $byConsultant = $rows->groupBy('author_user_id')->map(function ($set) {
            $mins = (int) $set->sum('effort_minutes');
            return ['name' => $set->first()->author?->name ?? 'Consultor', 'minutes' => $mins, 'duration' => self::humanDur($mins * 60)];
        })->values();

        return response()->json(['data' => [
            'items' => $items,
            'total_minutes'    => $totalMin,
            'total_duration'   => self::humanDur($totalMin * 60),
            'billable_minutes' => $chargeMin,
            'billable_duration' => self::humanDur($chargeMin * 60),
            'count' => $items->count(),
            'by_consultant' => $byConsultant,
            'scope' => $scope, // 'all' | 'own'
        ]]);
    }

    /**
     * Clonar chamado: abre um NOVO chamado com a mesma classificação (cliente, contrato, categoria,
     * serviço, prioridade, fila…). Sem histórico/interações/SLA da origem — nasce em "Novo", sem
     * responsável, com número próprio. Solicitante/descrição/tags são opcionais.
     */
    public function clone(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canSee($request->user(), $ticket), 403, 'Seu perfil de acesso não permite ver este chamado.');
        abort_unless($this->access->canClone($request->user()) && $this->access->canOpen($request->user()), 403, 'Seu perfil de acesso não permite clonar chamados.');

        $v = $request->validate([
            'subject'          => 'nullable|string|max:255',
            'copy_description' => 'boolean',
            'copy_requester'   => 'boolean',
            'copy_tags'        => 'boolean',
        ]);
        $copyDesc = $v['copy_description'] ?? true;
        $copyReq  = $v['copy_requester'] ?? true;
        $copyTags = $v['copy_tags'] ?? true;
        $u = $request->user();

        // Classificação herdada da origem.
        $data = [
            'subject'      => $v['subject'] ?? $ticket->subject,
            'description'  => $copyDesc ? $ticket->description : null,
            'customer_id'  => $ticket->customer_id,
            'contract_id'  => $ticket->contract_id,
            'project_id'   => $ticket->project_id,
            'category_id'  => $ticket->category_id,
            'service_id'   => $ticket->service_id,
            'justification_id' => $ticket->justification_id,
            'priority'     => $ticket->priority ?: 'normal',
            'channel'      => 'interno', // clone é aberto internamente
            'level'        => $ticket->level,
            'team_id'      => $ticket->team_id,
            'status_id'    => optional(HelpDeskStatus::default())->id,
            'created_by_id' => $u?->id,
            'last_activity_at' => now(),
        ];
        if ($copyReq) {
            $data['customer_contact_id'] = $ticket->customer_contact_id;
            $data['requester_user_id']   = $ticket->requester_user_id;
            $data['requester_name']      = $ticket->requester_name;
            $data['requester_email']     = $ticket->requester_email;
            $data['cc_emails']           = $ticket->cc_emails;
        }

        $new = HelpDeskTicket::create($data);
        $new->update(['ticket_number' => \App\Services\HelpDeskTicketNumber::next()]);
        if ($copyTags) {
            $tagIds = $ticket->tags()->pluck('helpdesk_tags.id')->all();
            if ($tagIds) $new->tags()->sync($tagIds);
        }

        $this->sla->apply($new);
        HelpDeskTicketEvent::log($new->id, 'created', ['to_value' => $new->subject, 'meta' => ['cloned_from' => $ticket->id, 'cloned_from_number' => $ticket->ticket_number]]);
        \App\Services\HelpDeskTriggerEngine::dispatch('ticket_created', $new, ['actor_id' => $u?->id, 'actor_email' => $u?->email]);

        return response()->json(['data' => ['id' => $new->id, 'ticket_number' => $new->ticket_number]], 201);
    }

    /**
     * Candidatar uma interação como artigo da Base de Conhecimento: cria um RASCUNHO com o corpo
     * da interação (título = assunto do chamado), pra revisão/publicação depois. Gate por perfil.
     */
    public function commentToKb(Request $request, HelpDeskTicket $ticket, HelpDeskTicketComment $comment): JsonResponse
    {
        abort_unless((int) $comment->ticket_id === (int) $ticket->id, 404);
        abort_unless($this->access->canSee($request->user(), $ticket), 403, 'Seu perfil de acesso não permite ver este chamado.');
        abort_unless($this->access->canCandidateKb($request->user(), $comment), 403, 'Seu perfil de acesso não permite candidatar artigos.');

        $title = mb_substr(trim((string) ($ticket->subject ?: 'Artigo')), 0, 200);
        $article = \App\Models\HelpDeskKbArticle::create([
            'title'          => $title,
            'slug'           => \Illuminate\Support\Str::slug($title) . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)),
            'excerpt'        => mb_substr(trim(strip_tags((string) $comment->body)), 0, 200),
            'body'           => (string) $comment->body,
            'status'         => 'draft',
            'visibility'     => $comment->visibility === 'internal' ? 'internal' : 'customer',
            'author_user_id' => $request->user()?->id,
        ]);
        HelpDeskTicketEvent::log($ticket->id, 'note', ['to_value' => 'Artigo KB (rascunho) criado', 'meta' => ['kb_article_id' => $article->id, 'from_comment' => $comment->id]]);

        return response()->json(['data' => ['id' => $article->id, 'title' => $article->title, 'status' => 'draft']], 201);
    }

    /**
     * Enviar e-mail avulso a partir do chamado: destinatários/assunto/corpo livres, enviado COMO a
     * conta do Help Desk. Registra uma nota interna com o conteúdo + evento de e-mail enviado.
     */
    public function sendEmail(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canSee($request->user(), $ticket), 403, 'Seu perfil de acesso não permite ver este chamado.');
        abort_unless($this->access->canSendEmail($request->user()), 403, 'Seu perfil de acesso não permite enviar e-mails.');

        $v = $request->validate([
            'to'      => 'required|array|min:1',
            'to.*'    => 'email',
            'cc'      => 'nullable|array',
            'cc.*'    => 'email',
            'subject' => 'required|string|max:255',
            'body'    => 'required|string',
        ]);
        $cc = $v['cc'] ?? [];

        [$ok, $err] = \App\Services\HelpDeskReplyMailer::sendStandalone($ticket, $v['to'], $cc, $v['subject'], $v['body']);
        abort_unless($ok, 422, 'Não foi possível enviar o e-mail: ' . ($err ?? 'erro desconhecido'));

        // Registro no chamado: nota INTERNA (destinatários podem ser terceiros) + evento.
        $u = $request->user();
        $header = '<p style="margin:0 0 8px"><strong>E-mail enviado</strong><br>'
            . 'Para: ' . e(implode(', ', $v['to']))
            . ($cc ? '<br>CC: ' . e(implode(', ', $cc)) : '')
            . '<br>Assunto: ' . e($v['subject']) . '</p><hr>';
        $comment = $ticket->comments()->create([
            'author_user_id' => $u?->id,
            'body'           => $header . $v['body'],
            'visibility'     => 'internal',
            'channel'        => 'email',
            'is_system'      => false,
        ]);
        HelpDeskTicketEvent::log($ticket->id, 'email_sent', [
            'meta' => ['to' => $v['to'], 'cc' => $cc, 'subject' => $v['subject'], 'comment_id' => $comment->id],
        ]);
        $ticket->last_activity_at = now();
        $ticket->save();

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * Reabertura agendada: agenda um chamado RESOLVIDO/ENCERRADO para reabrir automaticamente
     * numa data/hora futura. Um job (help-desk:run-scheduled-reopens) reabre quando a hora chega.
     */
    public function scheduleReopen(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canEdit($request->user(), $ticket), 403, 'Seu perfil não permite editar este chamado.');
        abort_unless($this->access->canReopen($request->user()), 403, 'Seu perfil de acesso não permite reabrir chamados.');
        $ticket->loadMissing('status');
        abort_unless($ticket->status && ($ticket->status->is_resolved || $ticket->status->is_terminal), 422, 'Só é possível agendar reabertura de chamados resolvidos ou encerrados.');

        $v = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'note' => 'nullable|string|max:500',
        ]);
        $tz   = $this->sla->resolvePolicy($ticket)?->slaTimezone() ?? 'America/Sao_Paulo';
        $when = \Illuminate\Support\Carbon::parse($v['date'] . ' ' . $v['time'], $tz)->setTimezone('UTC');
        abort_if($when->isPast(), 422, 'A data/hora da reabertura deve ser no futuro.');

        $ticket->reopen_scheduled_at    = $when;
        $ticket->reopen_scheduled_note  = $v['note'] ?? null;
        $ticket->reopen_scheduled_by_id = $request->user()?->id;
        $ticket->save();

        HelpDeskTicketEvent::log($ticket->id, 'reopen_scheduled', [
            'to_value' => $when->toIso8601String(),
            'meta'     => ['note' => $v['note'] ?? null],
        ]);
        return response()->json(['data' => $this->decorate($this->withRels(HelpDeskTicket::query())->find($ticket->id))]);
    }

    /** Cancela uma reabertura agendada pendente. */
    public function cancelScheduledReopen(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canEdit($request->user(), $ticket), 403, 'Seu perfil não permite editar este chamado.');
        if ($ticket->reopen_scheduled_at) {
            $ticket->reopen_scheduled_at    = null;
            $ticket->reopen_scheduled_note  = null;
            $ticket->reopen_scheduled_by_id = null;
            $ticket->save();
            HelpDeskTicketEvent::log($ticket->id, 'reopen_schedule_canceled', ['meta' => ['by' => $request->user()?->id]]);
        }
        return response()->json(['data' => $this->decorate($this->withRels(HelpDeskTicket::query())->find($ticket->id))]);
    }

    /** Duração legível a partir de segundos: "45s" / "12min" / "3h 20min" / "2d 4h". */
    private static function humanDur(int $s): string
    {
        if ($s < 60) return $s . 's';
        $m = intdiv($s, 60); if ($m < 60) return $m . 'min';
        $h = intdiv($m, 60); $mm = $m % 60; if ($h < 24) return $h . 'h' . ($mm ? ' ' . $mm . 'min' : '');
        $d = intdiv($h, 24); $hh = $h % 24; return $d . 'd' . ($hh ? ' ' . $hh . 'h' : '');
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
            'previousTicket:id,ticket_number,subject',                       // continuação de chamado encerrado
            'continuations:id,ticket_number,previous_ticket_id',            // chamado(s) abertos a partir DESTE
        ]);
        $events = $ticket->events()->where('event_type', 'status_changed')->orderBy('created_at')->get(['from_value', 'to_value', 'created_at']);
        $data = $this->enrichTicketFlags($ticket, $this->decorate($ticket, $events), \Illuminate\Support\Facades\Auth::user());
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
            'external_ticket_ref' => 'nullable|string|max:100', // ex.: nº do chamado no fornecedor (TOTVS)
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
        // Número no formato CONFIGURADO (prefixo + dígitos + sequência) — não hardcoded HD-######.
        $ticket->update(['ticket_number' => \App\Services\HelpDeskTicketNumber::next()]);
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
        // Encerrar = mover para status terminal (Fechado/Cancelado). Só coordenador/admin por padrão.
        $isClose = $new && $new->is_terminal && !(optional($ticket->status)->is_terminal);
        abort_if($isClose && !$this->access->canClose($request->user()), 422, 'Seu perfil de acesso não permite encerrar chamados.');
        if (array_key_exists('justification_id', $v)) {
            $ticket->justification_id = $v['justification_id'];
            $ticket->save();
        }
        $this->transitionStatus($ticket, $new, $v['note'] ?? null);
        $u = $request->user();
        \App\Services\HelpDeskTriggerEngine::dispatch('status_changed', $ticket->fresh(), ['actor_id' => $u?->id, 'actor_email' => $u?->email]);
        return response()->json(['data' => $this->decorate($this->withRels(HelpDeskTicket::query())->find($ticket->id))]);
    }

    /**
     * Agenda o chamado: define DATA (obrigatória) + HORA (opcional) de retomada e PAUSA o SLA.
     * Enquanto agendado, o relógio de SLA congela; a retomada empurra o prazo (resumeSchedule).
     */
    public function schedule(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        $v = $request->validate([
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'time' => 'nullable|date_format:H:i',
            'note' => 'nullable|string|max:500',
        ]);
        abort_unless($this->access->canEdit($request->user(), $ticket), 403, 'Seu perfil não permite editar este chamado.');

        $allDay = empty($v['time']);
        // Data/hora informada é LOCAL (fuso da política, ex.: SP). Sem hora → fim do dia agendado.
        $tz   = $this->sla->resolvePolicy($ticket)?->slaTimezone() ?? 'America/Sao_Paulo';
        $when = \Illuminate\Support\Carbon::parse($v['date'] . ' ' . ($v['time'] ?? '23:59'), $tz)->setTimezone('UTC');

        // Reagendar sobre um agendamento vigente: retoma antes (assa a pausa anterior no prazo).
        if ($ticket->sla_paused_at || $ticket->scheduled_until) $this->sla->resumeSchedule($ticket);

        // Se o status atual JÁ pausa o SLA (ex.: Aguardando cliente), NÃO seta sla_paused_at
        // (senão a pausa contaria em dobro). O agendamento só guarda a data + retomada.
        $statusPauses = $this->sla->isPausedByStatus($ticket);
        $ticket->scheduled_until   = $when;
        $ticket->scheduled_all_day = $allDay;
        $ticket->sla_paused_at     = $statusPauses ? null : now();
        $ticket->save();

        HelpDeskTicketEvent::log($ticket->id, 'scheduled', [
            'to_value' => $when->toIso8601String(),
            'meta'     => ['note' => $v['note'] ?? null, 'all_day' => $allDay],
        ]);
        return response()->json(['data' => $this->decorate($this->withRels(HelpDeskTicket::query())->find($ticket->id))]);
    }

    /** Cancela o agendamento e RETOMA o SLA (empurra o prazo pelos minutos úteis pausados). */
    public function unschedule(Request $request, HelpDeskTicket $ticket): JsonResponse
    {
        abort_unless($this->access->canEdit($request->user(), $ticket), 403, 'Seu perfil não permite editar este chamado.');
        if ($ticket->sla_paused_at || $ticket->scheduled_until) {
            $this->sla->resumeSchedule($ticket);
            HelpDeskTicketEvent::log($ticket->id, 'schedule_resumed', []);
        }
        return response()->json(['data' => $this->decorate($this->withRels(HelpDeskTicket::query())->find($ticket->id))]);
    }

    /** Transição de status com efeitos de SLA (resolução/reabertura/fechamento). Reutilizável. */
    private function transitionStatus(HelpDeskTicket $ticket, HelpDeskStatus $new, ?string $note = null): void
    {
        if ((int) $new->id === (int) $ticket->status_id) {
            return;
        }
        // Classificação obrigatória para CONCLUIR (resolver): Categoria, Serviço, Urgência e Nível
        // precisam estar preenchidos antes de mover o chamado para um status resolvido.
        if ($new->is_resolved && !optional($ticket->status)->is_resolved) {
            $faltando = [];
            if (!$ticket->category_id) $faltando[] = 'Categoria';
            if (!$ticket->service_id)  $faltando[] = 'Serviço';
            if (!$ticket->priority)    $faltando[] = 'Urgência';
            if (!$ticket->level)       $faltando[] = 'Nível';
            abort_if(!empty($faltando), 422, 'Preencha antes de concluir o atendimento: ' . implode(', ', $faltando) . '.');
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
     * Abertura do chamado em UMA chamada só: ticket + interações (40 recentes). Evita o backend free
     * re-inicializar o Laravel N vezes (show + comments eram 2 requests). O resto (anexos/apontamentos/
     * merged/reuniões) segue em chamadas próprias, adiadas — não bloqueiam o conteúdo visível.
     */
    public function detail(Request $request, HelpDeskTicket $ticket, AttachmentService $svc): JsonResponse
    {
        $user = $request->user();
        // $ticket já veio do route-model-binding: eager-load das relações no próprio modelo em vez de
        // refazer o SELECT do ticket (withRels(...)->find). Mesma decoração, uma query a menos.
        $ticket->load($this->detailRels());
        $ticketData = $this->enrichTicketFlags($ticket, $this->decorate($ticket), $user);

        $isCliente = (bool) $user?->isCliente();
        $base = fn () => $ticket->comments()->when($isCliente, fn ($x) => $x->where('visibility', 'customer'));
        $total = $base()->count();
        $cq = $base()->with(['author:id,name,type', 'contact:id,name'])->orderBy('created_at');
        if ($total > 40) {
            $recentIds = $base()->orderByDesc('created_at')->limit(40)->pluck('id');
            $cq->whereIn('id', $recentIds);
        }
        $comments = $cq->get();
        $attByComment = $svc->aggregateLoader('HELPDESK_TICKET_COMMENT', $comments->pluck('id')->all());
        $commentsData = $comments->map(fn ($c) => array_merge($c->toArray(), [
            'attachments'      => ($attByComment->get($c->id) ?? collect())->values(),
            'can_edit'         => $this->access->canEditComment($user, $c),
            'can_candidate_kb' => !$c->is_system && $c->author_user_id && $this->access->canCandidateKb($user, $c),
        ]));

        return response()->json(['data' => [
            'ticket'            => $ticketData,
            'comments'          => $commentsData,
            'comments_total'    => $total,
            'comments_returned' => $comments->count(),
        ]]);
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
        // Cliente só enxerga as respostas marcadas como visíveis ao cliente.
        $isCliente = (bool) $request->user()?->isCliente();
        $base = fn () => $ticket->comments()->when($isCliente, fn ($x) => $x->where('visibility', 'customer'));
        $total = $base()->count();
        $q = $base()->with(['author:id,name,type', 'contact:id,name'])->orderBy('created_at');
        // PAGINAÇÃO: por padrão traz só as N interações MAIS RECENTES (tickets grandes — 183 interações —
        // custavam ~6s pra montar). ?limit=0 (ou "todas") traz tudo. Mantém a ordem cronológica no retorno.
        $limit = (int) $request->input('limit', 0);
        if ($limit > 0 && $total > $limit) {
            $recentIds = $base()->orderByDesc('created_at')->limit($limit)->pluck('id');
            $q->whereIn('id', $recentIds);
        }
        $user = $request->user();
        $comments = $q->get();
        // Anti-N+1: anexos de TODAS as interações em UMA query (era 1 query de anexos POR comentário —
        // 183 comentários = 183 queries). aggregateLoader agrupa por entity_id (= id do comentário).
        $attByComment = $svc->aggregateLoader('HELPDESK_TICKET_COMMENT', $comments->pluck('id')->all());
        $data = $comments->map(function ($c) use ($attByComment, $user) {
            $arr = $c->toArray();
            $arr['attachments'] = ($attByComment->get($c->id) ?? collect())->values();
            $arr['can_edit'] = $this->access->canEditComment($user, $c);
            // Só faz sentido "virar artigo" a partir de interação da EQUIPE (não do cliente/sistema).
            $arr['can_candidate_kb'] = !$c->is_system && $c->author_user_id
                && $this->access->canCandidateKb($user, $c);
            return $arr;
        });
        // total = quantas existem; returned = quantas vieram (FE mostra "carregar mais antigas" se total>returned).
        return response()->json(['data' => $data, 'total' => $total, 'returned' => $comments->count()]);
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
        // Chamado FECHADO (status terminal) não recebe novas interações — reabrir antes.
        abort_if(optional($ticket->status)->is_terminal, 422, 'Chamado fechado — reabra o chamado para adicionar interações.');
        // Fluxo de FORMULÁRIO (dynamic/solution/gmud) envia via FormData p/ mandar os arquivos JUNTO
        // do comentário (senão subiriam depois e o e-mail já teria saído sem anexo). Aí 'solution'
        // chega como string JSON — decodifica p/ array antes de validar.
        if (is_string($request->input('solution')) && $request->input('solution') !== '') {
            $request->merge(['solution' => json_decode((string) $request->input('solution'), true)]);
        }
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
                // Assinatura do usuário LOGADO (a MESMA do cadastro: foto/cargo/fallback institucional)
                // ao final da RESPOSTA AO CLIENTE com texto. Nota interna e anexo-só não assinam.
                $body = $v['body'] ?? '';
                if (($v['visibility'] ?? 'internal') === 'customer' && trim((string) $body) !== '') {
                    $sig = \App\Services\SignatureRenderer::resolveFor($request->user());
                    if (\App\Services\SignatureRenderer::hasData($sig)) {
                        // Assinatura COMPLETA (com a faixa "LET'S DO IT"). No dark mode do Apple Mail a
                        // faixa transparente ganha uma moldura fina (placa do cliente) — comportamento
                        // aceito pelo usuário, que quer todos os componentes da assinatura no e-mail.
                        $body .= '<div style="margin-top:16px;">' . \App\Services\SignatureRenderer::render($sig, 'data', true, 'light') . '</div>';
                    }
                }
                $comment = $ticket->comments()->create([
                    'author_user_id'  => $request->user()?->id,
                    'body'            => $body,
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
