<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskCategory;
use App\Models\HelpDeskEmailAccount;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTeam;
use App\Models\HelpDeskTrigger;
use App\Models\HelpDeskTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Help Desk — Gatilhos (automação). CRUD + metadados do builder + receitas prontas. */
class HelpDeskTriggerController extends Controller
{
    use \App\Http\Traits\FiltersByActiveCompany;

    public function index(): JsonResponse
    {
        return response()->json(['data' => HelpDeskTrigger::with('createdBy:id,name')->orderBy('run_order')->orderBy('id')->get()]);
    }

    private function rules(bool $creating): array
    {
        return [
            'name'            => ($creating ? 'required' : 'sometimes') . '|string|max:160',
            'enabled'         => 'nullable|boolean',
            'event'           => ($creating ? 'required' : 'sometimes') . '|in:' . implode(',', array_keys(HelpDeskTrigger::EVENTS)),
            'condition_logic' => 'nullable|in:all,any',
            'conditions'      => 'nullable|array',
            'actions'         => 'nullable|array',
            'recipe'          => 'nullable|string|max:60',
            'run_order'       => 'nullable|integer',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $v['created_by_id'] = $request->user()?->id;
        return response()->json(['data' => HelpDeskTrigger::create($v)->fresh('createdBy:id,name')], 201);
    }

    public function update(Request $request, HelpDeskTrigger $trigger): JsonResponse
    {
        $trigger->update($request->validate($this->rules(false)));
        return response()->json(['data' => $trigger->fresh('createdBy:id,name')]);
    }

    public function destroy(HelpDeskTrigger $trigger): JsonResponse
    {
        $trigger->delete();
        return response()->json(null, 204);
    }

    /** Metadados p/ o builder (eventos, campos, operadores, ações) + listas de opções. */
    public function meta(): JsonResponse
    {
        $agentIds = \Illuminate\Support\Facades\DB::table('helpdesk_team_user')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q
                ->join('helpdesk_teams', 'helpdesk_teams.id', '=', 'helpdesk_team_user.helpdesk_team_id')
                ->where('helpdesk_teams.company_id', $cid))
            ->distinct()->pluck('helpdesk_team_user.user_id');

        return response()->json(['data' => [
            'events'      => HelpDeskTrigger::EVENTS,
            'catalog'     => HelpDeskTrigger::conditionCatalog(),
            'operators'   => HelpDeskTrigger::OPERATORS,
            'actionTypes' => HelpDeskTrigger::ACTION_TYPES,
            'recipients'  => ['responsavel' => 'Responsável pelo chamado', 'cliente' => 'Cliente (solicitante)', 'requester' => 'Usuário solicitante', 'coordenador_sustentacao' => 'Coordenador de Sustentação'],
            'blocks'      => \App\Services\HelpDeskMailComposer::BLOCKS,
            // Fontes de opções (referenciadas por 'source' no catálogo).
            'channels'     => collect(\App\Models\HelpDeskTicket::CHANNELS)->map(fn ($c) => ['id' => $c, 'name' => $c]),
            'priorities'   => collect(\App\Models\HelpDeskTicket::PRIORITIES)->map(fn ($p) => ['id' => $p, 'name' => $p]),
            'commentBy'    => [['id' => 'client', 'name' => 'Cliente'], ['id' => 'agent', 'name' => 'Agente']],
            'visibilities' => [['id' => 'customer', 'name' => 'Pública (cliente vê)'], ['id' => 'internal', 'name' => 'Nota interna']],
            'statuses'     => HelpDeskStatus::orderBy('sort_order')->get(['id', 'label as name']),
            'categories'   => HelpDeskCategory::orderBy('name')->get(['id', 'name']),
            'services'     => \App\Models\HelpDeskService::orderBy('name')->get(['id', 'parent_id', 'name']),
            'teams'        => HelpDeskTeam::orderBy('name')->get(['id', 'name']),
            'tags'         => HelpDeskTag::orderBy('name')->get(['id', 'name', 'color']),
            'agents'       => \App\Models\User::whereIn('id', $agentIds)->orderBy('name')->get(['id', 'name']),
            'justifications' => \App\Models\HelpDeskTicketJustification::orderBy('name')->get(['id', 'name']),
            'accounts'     => HelpDeskEmailAccount::where('enabled', true)->get(['id', 'name', 'email']),
            'placeholders' => [
                '{ticket.number}', '{ticket.protocol}', '{ticket.subject}', '{ticket.url}',
                '{ticket.client.name}', '{ticket.customer}', '{ticket.requester}', '{ticket.assignee}',
                '{ticket.status}', '{ticket.priority}', '{ticket.category}', '{ticket.service}', '{ticket.team}',
                '{ticket.created_at}', '{ticket.description}', '{ticket.summary.public.actions}',
                '{ticket.firstaction.attachments}', '{tenant.name}',
            ],
        ]]);
    }

    /** Prévia do e-mail. Modo template (message+blocks) → HTML institucional; modo raw (body) → legado. */
    public function previewEmail(Request $request): JsonResponse
    {
        $v = $request->validate([
            'subject' => 'nullable|string', 'body' => 'nullable|string',
            'notification_title' => 'nullable|string', 'notification_subtitle' => 'nullable|string',
            'message' => 'nullable|string', 'blocks' => 'nullable|array', 'to' => 'nullable|array',
        ]);
        $ticket = \App\Models\HelpDeskTicket::orderByDesc('id')->first()
            ?? new \App\Models\HelpDeskTicket(['ticket_number' => 'HD-000000', 'subject' => 'Assunto de exemplo', 'priority' => 'normal']);

        $subject = \App\Services\HelpDeskTriggerEngine::render((string) ($v['subject'] ?? ''), $ticket);
        $isTemplate = $request->has('message') || $request->has('blocks');

        if ($isTemplate) {
            $toList = (array) ($v['to'] ?? []);
            $audience = (in_array('cliente', $toList, true) || in_array('requester', $toList, true)) ? 'cliente'
                : (in_array('responsavel', $toList, true) ? 'responsavel' : 'interno');
            $html = \App\Services\HelpDeskMailComposer::compose(
                (string) ($v['message'] ?? ''), (array) ($v['blocks'] ?? []), $ticket,
                \App\Services\HelpDeskMailFooter::logoDataUri(), $audience,
                isset($v['notification_title']) ? (string) $v['notification_title'] : null,
                isset($v['notification_subtitle']) ? (string) $v['notification_subtitle'] : null,
            );
            return response()->json(['data' => ['mode' => 'template', 'subject' => $subject, 'html' => $html, 'sample' => $ticket->ticket_number]]);
        }

        return response()->json(['data' => [
            'mode'    => 'raw',
            'subject' => $subject,
            'body'    => \App\Services\HelpDeskTriggerEngine::render((string) ($v['body'] ?? ''), $ticket),
            'footer'  => \App\Services\HelpDeskMailFooter::previewHtml(),
            'sample'  => $ticket->ticket_number,
        ]]);
    }

    /** Template institucional (layout global). GET = atual; PUT = atualiza. */
    public function commTemplate(): JsonResponse
    {
        return response()->json(['data' => \App\Models\HelpDeskCommTemplate::current()]);
    }

    public function updateCommTemplate(Request $request): JsonResponse
    {
        $v = $request->validate([
            'company_name' => 'sometimes|string|max:120',
            'primary_color'=> 'sometimes|string|max:9',
            'font'         => 'sometimes|string|max:120',
            'button_label' => 'sometimes|string|max:60',
            'footer_text'  => 'sometimes|string|max:255',
            'signature'    => 'sometimes|string|max:255',
            'show_minutor' => 'sometimes|boolean',
            'ticket_prefix'  => 'sometimes|nullable|string|max:10',
            'ticket_padding' => 'sometimes|integer|min:1|max:12',
            'ticket_next'    => 'sometimes|integer|min:1',
        ]);
        $tpl = \App\Models\HelpDeskCommTemplate::current();
        // "Iniciador": o próximo número desejado → guarda como sequência (next - 1).
        if (array_key_exists('ticket_next', $v)) { $tpl->ticket_sequence = max(0, (int) $v['ticket_next'] - 1); unset($v['ticket_next']); }
        // prefixo NUNCA null (coluna NOT NULL): vazio é '' (formato só número).
        if (array_key_exists('ticket_prefix', $v)) { $v['ticket_prefix'] = (string) ($v['ticket_prefix'] ?? ''); }
        $tpl->fill($v)->save();
        return response()->json(['data' => $tpl->fresh()]);
    }

    /** Biblioteca de modelos de e-mail (assunto + mensagem + blocos sugeridos). */
    public function emailTemplates(): JsonResponse
    {
        return response()->json(['data' => self::EMAIL_TEMPLATES]);
    }

    public const EMAIL_TEMPLATES = [
        ['key' => 'blank',          'name' => 'Em branco',            'subject' => '',                                            'message' => '', 'blocks' => []],
        ['key' => 'assigned',       'name' => 'Chamado atribuído',    'subject' => 'Chamado {ticket.number} atribuído a você',    'message' => "Um chamado foi atribuído a você na triagem.\nAnalise o atendimento e inicie o tratamento assim que possível.", 'blocks' => ['ticket_data', 'summary', 'button']],
        ['key' => 'created',        'name' => 'Chamado criado',       'subject' => 'Novo chamado {ticket.number}',                'message' => "Um novo chamado foi aberto.", 'blocks' => ['ticket_data', 'summary', 'button']],
        ['key' => 'client_replied', 'name' => 'Cliente respondeu',    'subject' => 'O cliente respondeu o chamado {ticket.number}','message' => "O cliente enviou uma nova resposta. Verifique e dê andamento.", 'blocks' => ['last_public', 'button']],
        ['key' => 'request_evidence','name'=> 'Solicitar evidências', 'subject' => 'Precisamos de mais informações — {ticket.number}', 'message' => "Para prosseguir com o atendimento, precisamos de mais detalhes/evidências sobre a solicitação.", 'blocks' => ['summary', 'button']],
        ['key' => 'resolved',       'name' => 'Chamado resolvido',    'subject' => 'Chamado {ticket.number} resolvido',           'message' => "Seu chamado foi resolvido. Caso ainda precise de ajuda, é só responder este e-mail.", 'blocks' => ['ticket_data', 'button']],
        ['key' => 'reopened',       'name' => 'Reabertura',           'subject' => 'Chamado {ticket.number} reaberto',            'message' => "O chamado foi reaberto e voltou para atendimento.", 'blocks' => ['ticket_data', 'last_public', 'button']],
        ['key' => 'sla_breached',   'name' => 'SLA vencido',          'subject' => '⚠ SLA vencido — chamado {ticket.number}',     'message' => "O SLA deste chamado venceu. Priorize o atendimento.", 'blocks' => ['ticket_data', 'sla', 'button']],
    ];

    /** Receitas: automações prontas com poucos campos (modo fácil). */
    public function recipes(): JsonResponse
    {
        return response()->json(['data' => self::RECIPES]);
    }

    /** Cria um gatilho a partir de uma receita + respostas. */
    public function applyRecipe(Request $request): JsonResponse
    {
        $key = (string) $request->input('recipe');
        $in  = (array) $request->input('inputs', []);
        $recipe = collect(self::RECIPES)->firstWhere('key', $key);
        abort_if(!$recipe, 422, 'Receita desconhecida.');

        $built = $this->buildFromRecipe($key, $in);
        $built['name']          = $in['name'] ?? $recipe['name'];
        $built['recipe']        = $key;
        $built['enabled']       = true;
        $built['created_by_id'] = $request->user()?->id;

        return response()->json(['data' => HelpDeskTrigger::create($built)], 201);
    }

    /** Catálogo das receitas (o front renderiza os inputs). */
    public const RECIPES = [
        ['key' => 'notify_on_open', 'name' => 'Notificar quando um chamado abre', 'icon' => 'BellRing',
         'desc' => 'Envia um e-mail assim que um chamado é aberto.',
         'inputs' => [['k' => 'to', 'label' => 'Enviar para', 'type' => 'recipient'], ['k' => 'channels', 'label' => 'Só quando aberto via', 'type' => 'channels']]],
        ['key' => 'confirm_to_client', 'name' => 'Confirmar recebimento ao cliente', 'icon' => 'MailCheck',
         'desc' => 'Responde ao cliente confirmando que o chamado foi recebido (quando aberto por e-mail).',
         'inputs' => []],
        ['key' => 'reopen_on_client_reply', 'name' => 'Reabrir quando o cliente responde', 'icon' => 'Undo2',
         'desc' => 'Muda o status para "em atendimento" quando o cliente responde um chamado parado.',
         'inputs' => [['k' => 'to_status', 'label' => 'Mudar para o status', 'type' => 'status']]],
        ['key' => 'set_field_by_service', 'name' => 'Alterar campo conforme o serviço', 'icon' => 'Replace',
         'desc' => 'Quando o serviço for X, define automaticamente a equipe/categoria/urgência.',
         'inputs' => [['k' => 'service_id', 'label' => 'Quando o serviço for', 'type' => 'service'], ['k' => 'field', 'label' => 'Definir o campo', 'type' => 'field'], ['k' => 'value', 'label' => 'Com o valor', 'type' => 'fieldvalue']]],
        ['key' => 'autoclose_idle', 'name' => 'Auto-fechar chamados parados', 'icon' => 'TimerOff',
         'desc' => 'Fecha automaticamente chamados que ficam parados num status por X dias sem atividade.',
         'inputs' => [['k' => 'status_id', 'label' => 'Quando estiver no status', 'type' => 'status'], ['k' => 'days', 'label' => 'Parado por (dias)', 'type' => 'number'], ['k' => 'to_status', 'label' => 'Mudar para o status', 'type' => 'status']]],
    ];

    /** Traduz (receita, inputs) → {event, conditions, actions}. */
    private function buildFromRecipe(string $key, array $in): array
    {
        return match ($key) {
            'notify_on_open' => [
                'event'      => 'ticket_created',
                'condition_logic' => 'any',
                'conditions' => array_values(array_map(fn ($c) => ['field' => 'channel', 'operator' => 'eq', 'value' => $c], (array) ($in['channels'] ?? []))),
                'actions'    => [['type' => 'send_email', 'params' => [
                    'to' => [$in['to'] ?? 'responsavel'],
                    'layout' => 'template',
                    'subject' => 'Novo chamado {ticket.number}: {ticket.subject}',
                    'message' => 'Um novo chamado foi aberto.',
                    'blocks' => ['ticket_data', 'summary', 'button'],
                ]]],
            ],
            'confirm_to_client' => [
                'event'      => 'ticket_created',
                'condition_logic' => 'all',
                'conditions' => [['field' => 'channel', 'operator' => 'eq', 'value' => 'email']],
                'actions'    => [['type' => 'send_email', 'params' => [
                    'to' => ['cliente'],
                    'layout' => 'template',
                    'subject' => 'Recebemos seu chamado {ticket.number}',
                    'message' => 'Recebemos sua solicitação e ela foi registrada. Em breve retornaremos com o andamento.',
                    'blocks' => ['ticket_data', 'summary'],
                ]]],
            ],
            'reopen_on_client_reply' => [
                'event'      => 'comment_added',
                'condition_logic' => 'all',
                'conditions' => [['field' => 'comment_by', 'operator' => 'eq', 'value' => 'client']],
                'actions'    => [['type' => 'change_status', 'params' => ['status_id' => (int) ($in['to_status'] ?? 0)]]],
            ],
            'set_field_by_service' => [
                'event'      => 'field_changed',
                'condition_logic' => 'all',
                'conditions' => [['field' => 'service_id', 'operator' => 'eq', 'value' => (int) ($in['service_id'] ?? 0)]],
                'actions'    => [['type' => 'set_field', 'params' => ['field' => $in['field'] ?? 'team_id', 'value' => $in['value'] ?? null]]],
            ],
            'autoclose_idle' => [
                'event'      => 'idle_in_status',
                'condition_logic' => 'all',
                'conditions' => [
                    ['field' => 'status_id', 'operator' => 'eq', 'value' => (int) ($in['status_id'] ?? 0)],
                    ['field' => 'idle_hours', 'operator' => 'gte', 'value' => (int) ($in['days'] ?? 3) * 24],
                ],
                'actions'    => [['type' => 'change_status', 'params' => ['status_id' => (int) ($in['to_status'] ?? 0)]]],
            ],
            default => ['event' => 'ticket_created', 'conditions' => [], 'actions' => []],
        };
    }
}
