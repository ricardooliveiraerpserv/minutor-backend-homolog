<?php

namespace App\Http\Controllers;

use App\Meetings\MeetingOriginRegistry;
use App\Meetings\MeetingService;
use App\Models\Meeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central de Reuniões — API. Uma reunião é criável de qualquer ORIGEM (chamado/projeto/cliente/
 * contrato/agenda); a diferença é só origin_type/origin_id. Reusa MeetingService (regra) + adapter.
 */
class MeetingController extends Controller
{
    public function __construct(private MeetingService $svc)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = Meeting::with(['participants', 'organizer:id,name'])->orderByDesc('starts_at');

        if ($request->filled('origin_type') && $request->filled('origin_id')) {
            $q->where('origin_type', $request->origin_type)->where('origin_id', (int) $request->origin_id);
        }
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('provider')) $q->where('provider', $request->provider);
        if ($request->filled('from')) $q->where('starts_at', '>=', $request->from);
        if ($request->filled('to')) $q->where('starts_at', '<=', $request->to);
        if ($request->filled('search')) $q->where('title', 'ilike', '%' . $request->search . '%');

        $meetings = $q->limit(300)->get();
        $refs = $this->resolveOriginRefs($meetings);
        return response()->json(['data' => $meetings->map(fn ($m) => $this->decorate($m) + [
            'origin_ref' => $refs["{$m->origin_type}:{$m->origin_id}"] ?? null,
        ])]);
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

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'title'                          => 'required|string|max:200',
            'description'                    => 'nullable|string',
            'provider'                       => 'nullable|in:teams,meet,zoom,webex,presencial',
            'starts_at'                      => 'required|date',
            'duration_minutes'               => 'nullable|integer|min:5|max:1440',
            'timezone'                       => 'nullable|string|max:40',
            'origin_type'                    => 'nullable|string|max:40',
            'origin_id'                      => 'nullable|integer',
            'organizer_user_id'              => 'nullable|integer|exists:users,id',
            'participants'                   => 'nullable|array',
            'participants.*.user_id'         => 'nullable|integer',
            'participants.*.customer_contact_id' => 'nullable|integer',
            'participants.*.email'           => 'nullable|email',
            'participants.*.name'            => 'nullable|string|max:190',
            'participants.*.role'            => 'nullable|string|max:20',
            'send_invites'                   => 'nullable|boolean',
        ]);
        if (!empty($v['origin_type'])) {
            abort_unless(MeetingOriginRegistry::isValid($v['origin_type']), 422, 'Origem de reunião inválida.');
        }
        $meeting = $this->svc->create($v, $request->user());
        return response()->json(['data' => $this->decorate($meeting, true)], 201);
    }

    public function show(Meeting $meeting): JsonResponse
    {
        return response()->json(['data' => $this->decorate($meeting->load(['participants.user:id,name', 'participants.contact:id,name', 'organizer:id,name']), true)]);
    }

    public function reschedule(Request $request, Meeting $meeting): JsonResponse
    {
        $v = $request->validate(['starts_at' => 'required|date', 'duration_minutes' => 'nullable|integer|min:5|max:1440']);
        $this->svc->reschedule($meeting, $v['starts_at'], $v['duration_minutes'] ?? null);
        return response()->json(['data' => $this->decorate($meeting->fresh(['participants']), true)]);
    }

    public function cancel(Request $request, Meeting $meeting): JsonResponse
    {
        $this->svc->cancel($meeting, $request->input('reason'));
        return response()->json(['data' => $this->decorate($meeting->fresh(), true)]);
    }

    public function start(Meeting $meeting): JsonResponse
    {
        $this->svc->start($meeting);
        return response()->json(['data' => $this->decorate($meeting->fresh(), true)]);
    }

    public function end(Meeting $meeting): JsonResponse
    {
        $this->svc->end($meeting);
        return response()->json(['data' => $this->decorate($meeting->fresh(), true)]);
    }

    /**
     * Apontar as horas da reunião — REUSA TimesheetController::store (mesmo endpoint, zero lógica
     * paralela). Resolve o projeto pela origem (projeto direto, ou o projeto do chamado). Vincula
     * ao chamado quando a origem é um ticket. Registra 'hours_logged' na timeline da reunião.
     */
    public function logHours(Request $request, Meeting $meeting): JsonResponse
    {
        $v = $request->validate([
            'project_id'     => 'nullable|integer|exists:projects,id',
            'effort_minutes' => 'nullable|integer|min:1|max:1440',
            'description'    => 'nullable|string|max:5000',
        ]);

        $projectId = $v['project_id'] ?? $this->resolveProjectForMeeting($meeting);
        abort_unless($projectId, 422, 'Não foi possível determinar o projeto — informe o projeto para apontar as horas.');

        $minutes  = $v['effort_minutes'] ?? $meeting->duration_minutes ?? 30;
        $ticketId = $meeting->origin_type === 'HELPDESK_TICKET' ? $meeting->origin_id : null;

        // Monta a request no formato do store e reusa o fluxo oficial (saldo/competência/observer/etc.).
        $tsReq = Request::create('/timesheets', 'POST', [
            'project_id'         => $projectId,
            'date'               => optional($meeting->starts_at)->toDateString(),
            'total_hours'        => sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60),
            'observation'        => $v['description'] ?? ('Reunião: ' . $meeting->title),
            'helpdesk_ticket_id' => $ticketId,
        ]);
        $tsReq->setUserResolver(fn () => $request->user());

        $resp = app(\App\Http\Controllers\TimesheetController::class)->store($tsReq);
        if ($resp->getStatusCode() >= 400) return $resp; // propaga erro de validação/permissão do store

        $tsId = data_get($resp->getData(true), 'data.id') ?? data_get($resp->getData(true), 'id');
        \App\Models\MeetingEvent::log($meeting->id, 'hours_logged', ['meta' => ['minutes' => $minutes, 'timesheet_id' => $tsId, 'project_id' => $projectId]]);

        return response()->json(['data' => ['ok' => true, 'timesheet_id' => $tsId, 'minutes' => $minutes]], 201);
    }

    /**
     * Pós-reunião (Fase 4 — estrutura): salva RESUMO e ATA. Hoje manual; a IA/transcrição preenche
     * os mesmos campos depois, sem mudar a API. Registra o marco na timeline.
     */
    public function saveSummary(Request $request, Meeting $meeting): JsonResponse
    {
        $v = $request->validate(['summary' => 'required|string|max:20000']);
        $meeting->update(['summary' => $v['summary']]);
        \App\Models\MeetingEvent::log($meeting->id, 'summary_ready', []);
        return response()->json(['data' => ['ok' => true]]);
    }

    public function saveAta(Request $request, Meeting $meeting): JsonResponse
    {
        $v = $request->validate(['ata' => 'required|string|max:50000']);
        $meeting->update(['ata' => $v['ata']]);
        \App\Models\MeetingEvent::log($meeting->id, 'ata_generated', []);
        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * Fase 4 — gera RESUMO + ATA por IA a partir da transcrição (do corpo ou já salva na reunião).
     * A transcrição fica guardada p/ reprocesso; quando o Teams estiver ligado, ele preenche o mesmo
     * campo automaticamente e este endpoint não muda. Persiste + registra na timeline.
     */
    public function generateNotes(Request $request, Meeting $meeting, \App\Services\MeetingAiService $ai): JsonResponse
    {
        abort_unless($ai->isConfigured(), 422, 'Geração por IA não está configurada (ANTHROPIC_API_KEY ausente).');

        $v = $request->validate(['transcript' => 'nullable|string|max:200000']);
        $transcript = trim((string) ($v['transcript'] ?? $meeting->transcript ?? ''));
        abort_if($transcript === '', 422, 'Sem transcrição para gerar o resumo. Cole a transcrição da reunião.');

        if ($transcript !== trim((string) $meeting->transcript)) {
            $meeting->transcript = $transcript; // guarda p/ reprocesso
        }

        $out = $ai->generate($transcript, $meeting->title);
        abort_if($out === null, 502, 'A IA não conseguiu gerar o resumo agora. Tente novamente ou preencha manualmente.');

        $meeting->summary = $out['summary'] ?: $meeting->summary;
        $meeting->ata     = $out['ata'] ?: $meeting->ata;
        $meeting->save();

        \App\Models\MeetingEvent::log($meeting->id, 'summary_ready', ['meta' => ['source' => 'ai']]);
        if ($out['ata']) {
            \App\Models\MeetingEvent::log($meeting->id, 'ata_generated', ['meta' => ['source' => 'ai']]);
        }

        return response()->json(['data' => ['summary' => $meeting->summary, 'ata' => $meeting->ata]]);
    }

    /**
     * Pós-reunião no CHAMADO (Fase 3): registra uma nota interna no ticket de origem resumindo a
     * reunião — REUSA a criação de interação do Help Desk (sem lógica paralela).
     */
    public function noteToTicket(Request $request, Meeting $meeting): JsonResponse
    {
        abort_unless($meeting->origin_type === 'HELPDESK_TICKET' && $meeting->origin_id, 422, 'Reunião não está vinculada a um chamado.');
        $ticket = \App\Models\HelpDeskTicket::findOrFail($meeting->origin_id);

        $parts = $meeting->participants->map(fn ($p) => $p->name ?: $p->email)->filter()->implode(', ');
        $body = '<p><strong>📅 Reunião realizada</strong> — ' . e($meeting->title) . '<br>'
            . e(optional($meeting->starts_at)->format('d/m/Y H:i')) . ($meeting->duration_minutes ? ' · ' . $meeting->duration_minutes . ' min' : '')
            . ($parts ? '<br>Participantes: ' . e($parts) : '') . '</p>'
            . ($meeting->summary ? '<hr>' . nl2br(e($meeting->summary)) : '');

        $comment = $ticket->comments()->create([
            'author_user_id' => $request->user()?->id,
            'body'           => $body,
            'visibility'     => 'internal',
            'channel'        => 'interno',
        ]);
        \App\Models\HelpDeskTicketEvent::log($ticket->id, 'note', ['to_value' => 'Reunião registrada', 'meta' => ['meeting_id' => $meeting->id, 'comment_id' => $comment->id]]);

        return response()->json(['data' => ['ok' => true, 'comment_id' => $comment->id]], 201);
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

    /**
     * PORTAL do cliente — reuniões do cliente logado (escopo: origem = cliente, ou chamado do cliente,
     * ou o próprio contato como participante). Payload LEVE (sem detalhes internos).
     */
    public function portalMeetings(Request $request): JsonResponse
    {
        $cid = $request->user()?->customer_id;
        abort_unless($cid, 403, 'Usuário não vinculado a um cliente.');

        $contactIds = \App\Models\CustomerContact::where('customer_id', $cid)->pluck('id');
        $ticketIds  = \App\Models\HelpDeskTicket::where('customer_id', $cid)->pluck('id');

        $meetings = Meeting::with('participants')->where(function ($w) use ($cid, $ticketIds, $contactIds) {
            $w->where(fn ($x) => $x->where('origin_type', 'CUSTOMER')->where('origin_id', $cid))
              ->orWhere(fn ($x) => $x->where('origin_type', 'HELPDESK_TICKET')->whereIn('origin_id', $ticketIds))
              ->orWhereHas('participants', fn ($p) => $p->whereIn('customer_contact_id', $contactIds));
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

    /** Remove (soft-delete) uma reunião CANCELADA — "limpar" da agenda. Só canceladas, por segurança. */
    public function destroy(Meeting $meeting): JsonResponse
    {
        abort_unless($meeting->status === 'canceled', 422, 'Só é possível limpar reuniões canceladas.');
        \App\Models\MeetingEvent::where('meeting_id', $meeting->id)->delete();
        $meeting->delete();
        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * Eventos do CALENDÁRIO Microsoft do usuário logado (Meu Dia) no intervalo — pra Central de
     * Reuniões mostrar a agenda junto das reuniões do Minutor. Read-only; reusa MicrosoftCalendarService.
     * Sempre 200: {connected, configured, data:[...]} — nunca trava a Central.
     */
    public function calendarEvents(Request $request, \App\Services\MicrosoftCalendarService $cal): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);

        $from = $request->filled('from') ? \Illuminate\Support\Carbon::parse($request->query('from')) : now()->startOfWeek();
        $to   = $request->filled('to')   ? \Illuminate\Support\Carbon::parse($request->query('to'))   : now()->endOfWeek();

        $integ = \App\Models\UserIntegration::where('user_id', $u->id)->where('provider', 'microsoft')->first();
        if (!$integ) {
            return response()->json(['connected' => false, 'configured' => \App\Services\MicrosoftCalendarService::configured(), 'data' => []]);
        }
        $token = \App\Services\MicrosoftCalendarService::freshTokenFor($integ);
        if (!$token) {
            return response()->json(['connected' => true, 'reauth' => true, 'configured' => true, 'data' => []]);
        }

        $events = $cal->fetchAgenda($token, $from, $to);
        return response()->json(['connected' => true, 'configured' => true, 'account_email' => $integ->account_email, 'data' => $events]);
    }

    /** Participantes SUGERIDOS a partir da origem (ex.: chamado → solicitante + responsável). */
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

    private function decorate(Meeting $m, bool $withEvents = false): array
    {
        $data = [
            'id'                 => $m->id,
            'title'              => $m->title,
            'description'        => $m->description,
            'provider'           => $m->provider,
            'status'             => $m->status,
            'starts_at'          => optional($m->starts_at)->toIso8601String(),
            'ends_at'            => optional($m->ends_at)->toIso8601String(),
            'duration_minutes'   => $m->duration_minutes,
            'join_url'           => $m->join_url,
            'external_meeting_id' => $m->external_meeting_id,
            'organizer'          => $m->organizer ? ['id' => $m->organizer->id, 'name' => $m->organizer->name] : null,
            'origin_type'        => $m->origin_type,
            'origin_label'       => MeetingOriginRegistry::label($m->origin_type),
            'origin_id'          => $m->origin_id,
            'is_upcoming'        => $m->isUpcoming(),
            'is_past'            => $m->isPast(),
            'started_at'         => optional($m->started_at)->toIso8601String(),
            'ended_at'           => optional($m->ended_at)->toIso8601String(),
            'summary'            => $m->summary,
            'ata'                => $m->ata,
            'transcript'         => $m->transcript,
            'ai_enabled'         => (bool) config('services.anthropic.api_key'),
            'participants'       => $m->relationLoaded('participants') ? $m->participants->map(fn ($p) => [
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
