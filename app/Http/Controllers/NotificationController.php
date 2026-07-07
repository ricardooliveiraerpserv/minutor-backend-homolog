<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\NotificationRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Central de Notificações (tela inicial). Só usuários internos; cliente não vê. */
class NotificationController extends Controller
{
    private const INTERNAL = ['admin', 'administrativo', 'coordenador', 'consultor'];

    private function internalOrAbort(Request $request): \App\Models\User
    {
        $u = $request->user();
        abort_if(!$u || $u->type === 'cliente' || !in_array($u->type, self::INTERNAL, true), 403, 'Central de Notificações disponível apenas para usuários internos.');
        return $u;
    }

    /** Notificações visíveis ao usuário + estado de leitura/aceite + flags computadas. */
    public function index(Request $request): JsonResponse
    {
        $u = $this->internalOrAbort($request);

        $rows = $this->visibleQuery($u)
            ->with('poll.options')
            ->orderByRaw("case priority when 'critical' then 4 when 'high' then 3 when 'medium' then 2 else 1 end desc")
            ->orderByDesc('created_at')
            ->get();

        $reads = NotificationRead::where('user_id', $u->id)
            ->whereIn('notification_id', $rows->pluck('id'))->get()->keyBy('notification_id');

        $data = $rows->map(function (AppNotification $n) use ($reads, $u) {
            $r = $reads->get($n->id);
            // Aceite só vale se foi da versão ATUAL (versionamento → novo aceite).
            $acked  = $r && $r->ack_at && (int) $r->acked_version >= (int) $n->version;
            $viewed = (bool) ($r && $r->viewed_at);
            $poll = $n->type === 'poll' && $n->poll
                ? \App\Http\Controllers\NotificationPollController::results($n->poll, $u)
                : null;
            return array_merge($n->toArray(), [
                'viewed'      => $viewed,
                'acked'       => $acked,
                'pending_ack' => $n->requires_ack && !$acked,
                'ack_at'      => $r?->ack_at,
                'my_response' => $r?->response_action,   // botão de decisão que o usuário clicou
                'poll'        => $poll,
            ]);
        });

        return response()->json(['data' => [
            'notifications' => $data->values(),
            'pending_ack'   => $data->where('pending_ack', true)->count(),
            'unviewed'      => $data->where('viewed', false)->count(),
        ]]);
    }

    /** Contadores p/ os badges da navegação (tarefas atrasadas/pendentes + notificações não lidas). */
    public function badges(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);

        $today   = now()->toDateString();
        $nowTime = now()->format('H:i:s');
        $taskBase = \App\Models\Task::where('assigned_to', $u->id)->where('completed', false);
        $pendingTasks = (clone $taskBase)->count();
        $overdueTasks = (clone $taskBase)->where(function ($q) use ($today, $nowTime) {
            $q->whereDate('due_date', '<', $today)
              ->orWhere(fn ($w) => $w->whereDate('due_date', $today)->whereNotNull('due_time')->whereRaw('due_time < ?', [$nowTime]));
        })->count();

        // Notificações visíveis ao usuário ainda não vistas (ou com aceite pendente).
        $notifs = $this->visibleQuery($u)->get(['id', 'requires_ack', 'version']);
        $reads = NotificationRead::where('user_id', $u->id)
            ->whereIn('notification_id', $notifs->pluck('id'))->get()->keyBy('notification_id');
        $unread = $notifs->filter(function ($n) use ($reads) {
            $r = $reads->get($n->id);
            $viewed = $r && $r->viewed_at;
            $acked  = $r && $r->ack_at && (int) $r->acked_version >= (int) $n->version;
            return !$viewed || ($n->requires_ack && !$acked);
        })->count();

        return response()->json(['data' => [
            'pending_tasks'        => $pendingTasks,
            'overdue_tasks'        => $overdueTasks,
            'unread_notifications' => $unread,
            'critical'             => $overdueTasks > 0,
        ]]);
    }

    /** Query base de notificações VISÍVEIS ao usuário (não-expiradas + alvo). Reusada por index/stream. */
    private function visibleQuery(\App\Models\User $u)
    {
        return AppNotification::query()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q
                ->whereJsonContains('target_roles', $u->type)
                ->orWhereJsonContains('target_users', $u->id)
                ->orWhere('created_by', $u->id)   // o autor sempre vê o que publicou (acompanhamento + voto na própria enquete)
                ->when($u->contract_type, fn ($w) => $w->orWhereJsonContains('target_contract_types', $u->contract_type))
                ->when($u->customer_id, fn ($w) => $w
                    ->orWhere('target_customer_id', $u->customer_id)
                    ->orWhereJsonContains('target_customer_ids', $u->customer_id)))
            ->where('visible', true)        // ocultas não aparecem na Central (mas o e-mail ainda sai)
            ->where('is_template', false);  // modelos nunca aparecem p/ usuários
    }

    /**
     * Stream SSE (realtime): empurra um evento "notify" assim que surge notificação nova p/ o usuário.
     * Conexão limitada (~30s) — o EventSource reconecta sozinho com Last-Event-ID. Sem dependência extra.
     */
    public function stream(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $u = $this->internalOrAbort($request);
        $since = (int) ($request->header('Last-Event-ID') ?: $request->query('since', 0));

        return response()->stream(function () use ($u, $since) {
            while (ob_get_level() > 0) { @ob_end_flush(); }
            @set_time_limit(0);
            ignore_user_abort(true);
            echo "retry: 1500\n\n";  // reconexão em 1,5s ao fim de cada janela
            @flush();

            $last = $since;
            $deadline = time() + 30;
            while (time() < $deadline) {
                if (connection_aborted()) break;
                $max = (int) $this->visibleQuery($u)->max('id');
                if ($max > $last) {
                    $last = $max;
                    echo "id: {$max}\n";
                    echo "event: notify\n";
                    echo 'data: {"max":' . $max . "}\n\n";
                } else {
                    echo ": ping\n\n"; // heartbeat mantém a conexão viva
                }
                @flush();
                if (connection_aborted()) break;
                sleep(3);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    /** Registra visualização (não-bloqueante). */
    public function view(Request $request, AppNotification $notification): JsonResponse
    {
        $u = $this->internalOrAbort($request);
        $r = NotificationRead::firstOrNew(['notification_id' => $notification->id, 'user_id' => $u->id]);
        if (!$r->viewed_at) { $r->viewed_at = now(); $r->save(); }
        return response()->json(['data' => ['viewed' => true]]);
    }

    /** Registra ACEITE com auditoria (ip, user-agent, versão). */
    public function ack(Request $request, AppNotification $notification): JsonResponse
    {
        $u = $this->internalOrAbort($request);
        $r = NotificationRead::firstOrNew(['notification_id' => $notification->id, 'user_id' => $u->id]);
        $r->viewed_at      = $r->viewed_at ?? now();
        $r->ack_at         = now();
        $r->acked_version  = $notification->version;
        $r->ack_ip         = $request->ip();
        $r->ack_user_agent = (string) $request->userAgent();
        $r->save();
        return response()->json(['data' => ['acked' => true, 'ack_at' => $r->ack_at]]);
    }

    /** Registra a RESPOSTA do usuário a um botão de decisão (e marca como respondido → sai de pendente). */
    public function respond(Request $request, AppNotification $notification): JsonResponse
    {
        $u = $this->internalOrAbort($request);
        $action  = (string) $request->input('action');
        $actions = (array) ($notification->actions ?? []);
        abort_unless(in_array($action, $actions, true), 422, 'Opção inválida.');
        // Pode responder/mudar a resposta até o prazo da decisão (expires_at).
        abort_if($notification->expires_at && $notification->expires_at->isPast(), 422, 'O prazo da decisão já encerrou.');

        $r = NotificationRead::firstOrNew(['notification_id' => $notification->id, 'user_id' => $u->id]);
        $r->viewed_at       = $r->viewed_at ?? now();
        $r->response_action = $action;
        $r->ack_at          = now();                       // respondeu → não fica mais pendente
        $r->acked_version   = $notification->version;
        $r->ack_ip          = $request->ip();
        $r->ack_user_agent  = (string) $request->userAgent();
        $r->save();
        return response()->json(['data' => ['responded' => true, 'action' => $action]]);
    }

    /**
     * LOG de uma comunicação (admin): destinatários × visualizou/quando × resposta do botão.
     * Serve p/ "log de visualização de tudo que foi enviado" e "resultado dos botões de decisão".
     */
    public function log(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $recipients = $this->resolveRecipientUsers($notification);
        $reads = NotificationRead::where('notification_id', $notification->id)->get()->keyBy('user_id');

        $rows = $recipients->map(function ($usr) use ($reads) {
            $r = $reads->get($usr->id);
            return [
                'user_id'      => $usr->id,
                'user_name'    => $usr->name,
                'user_email'   => $usr->email,
                'viewed_at'    => $r?->viewed_at?->toIso8601String(),
                'response'     => $r?->response_action,
                'responded_at' => $r && $r->response_action ? $r->ack_at?->toIso8601String() : null,
            ];
        })->sortBy('user_name')->values();

        $actions = (array) ($notification->actions ?? []);
        $byAction = [];
        foreach ($actions as $a) $byAction[$a] = $rows->where('response', $a)->count();

        return response()->json(['data' => [
            'recipients' => $rows,
            'summary'    => [
                'total'     => $rows->count(),
                'viewed'    => $rows->whereNotNull('viewed_at')->count(),
                'responded' => $rows->whereNotNull('response')->count(),
                'actions'   => $actions,
                'by_action' => $byAction,
            ],
        ]]);
    }

    /** Usuários destinatários (perfis + usuários + contratação + cliente) — base do LOG. */
    private function resolveRecipientUsers(AppNotification $n)
    {
        $customerIds = array_filter(array_merge(
            (array) ($n->target_customer_ids ?? []),
            $n->target_customer_id ? [$n->target_customer_id] : []
        ));
        $hasTarget = $n->target_roles || $n->target_users || $n->target_contract_types || $customerIds;
        if (!$hasTarget) return collect();
        return \App\Models\User::query()
            ->where(function ($w) use ($n, $customerIds) {
                if ($n->target_roles)          $w->orWhereIn('type', $n->target_roles);
                if ($n->target_users)          $w->orWhereIn('id', $n->target_users);
                if ($n->target_contract_types) $w->orWhereIn('contract_type', $n->target_contract_types);
                if ($customerIds)              $w->orWhereIn('customer_id', $customerIds);
            })
            ->get(['id', 'name', 'email']);
    }

    /** Lista TODAS as notificações p/ gestão (admin) + nº de aceites. */
    public function manage(Request $request): JsonResponse
    {
        $admin = $request->user();
        abort_unless($admin?->isAdmin(), 403);
        // Avisos AUTO-GERADOS pelos lembretes de ação têm bloco próprio na Central — não duplicar na lista.
        $reminderIds = \App\Models\ActionReminderRule::whereNotNull('notification_id')->pluck('notification_id')->all();
        $rows = AppNotification::withCount(['reads as acks_count' => fn ($q) => $q->whereNotNull('ack_at')])
            ->with('poll.options')
            ->when($reminderIds, fn ($q) => $q->whereNotIn('id', $reminderIds))
            ->orderByDesc('created_at')->get()
            ->map(function (AppNotification $n) use ($admin) {
                $arr = $n->toArray();
                $arr['poll'] = $n->type === 'poll' && $n->poll
                    ? \App\Http\Controllers\NotificationPollController::results($n->poll, $admin)
                    : null;
                return $arr;
            });
        return response()->json(['data' => $rows]);
    }

    public function update(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $v = $this->validatePayload($request, false);
        $pollPayload = $v['poll'] ?? null;
        unset($v['poll']);
        if (array_key_exists('type', $v)) $v['requires_ack'] = $v['type'] === 'require_ack' ? true : (bool) ($v['requires_ack'] ?? $notification->requires_ack);
        if (array_key_exists('actions', $v) && !empty($v['actions'])) $v['requires_ack'] = true;   // botões de decisão exigem resposta
        if (array_key_exists('expires_at', $v)) $v['expires_at'] = $this->normalizeExpiry($v['expires_at']);
        if (array_key_exists('message', $v)) $v['message'] = $v['message'] ?? '';  // coluna NOT NULL (enquete tem msg opcional)
        \Illuminate\Support\Facades\DB::transaction(function () use ($notification, $v, $pollPayload) {
            $notification->update($v);
            if ($notification->type === 'poll' && $pollPayload) $this->savePoll($notification, $pollPayload);
        });
        return response()->json(['data' => $notification->fresh('poll.options')]);
    }

    public function destroy(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $notification->delete();
        return response()->json(null, 204);
    }

    /** Reenvia o aviso AGORA (admin): reabre p/ todos (limpa leituras) + reenvia e-mail + re-popa. */
    public function resend(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_if($notification->is_template, 422, 'Modelos não podem ser reenviados — use "Usar modelo" para publicar.');
        $emailed = $this->fire($notification);
        return response()->json(['data' => array_merge($notification->fresh('poll.options')->toArray(), ['emailed' => $emailed])]);
    }

    /**
     * "Dispara" um aviso de novo: zera as leituras (reaparece p/ todos), estende a validade até o
     * fim do dia, marca resent_at (o pop-up reaparece) e reenvia o e-mail. Reusado pelo reenvio
     * manual e pela recorrência. Retorna nº de e-mails enviados.
     */
    public function fire(AppNotification $n, array $extraBcc = []): int
    {
        NotificationRead::where('notification_id', $n->id)->delete();
        $n->forceFill([
            'resent_at'     => now(),
            'last_fired_at' => now(),
            'expires_at'    => $n->expires_at && $n->expires_at->isFuture() ? $n->expires_at : now()->endOfDay(),
        ])->save();
        return $this->emailNotification($n->fresh('poll.options'), $extraBcc);
    }

    /** Cria uma notificação (admin). */
    public function store(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u && $u->isAdmin(), 403, 'Apenas administradores podem publicar notificações.');
        $v = $this->validatePayload($request, true);
        $pollPayload = $v['poll'] ?? null;
        unset($v['poll']);
        $v['requires_ack'] = ($v['type'] ?? '') === 'require_ack' ? true : (bool) ($v['requires_ack'] ?? false);
        if (!empty($v['actions'])) $v['requires_ack'] = true;   // botões de decisão exigem resposta
        $v['message'] = $v['message'] ?? '';
        $v['expires_at'] = $this->normalizeExpiry($v['expires_at'] ?? null);
        $v['created_by'] = $u->id;

        $n = \Illuminate\Support\Facades\DB::transaction(function () use ($v, $pollPayload) {
            $n = AppNotification::create($v);
            if (($v['type'] ?? '') === 'poll' && $pollPayload) {
                $this->savePoll($n, $pollPayload);
            }
            return $n;
        });

        $emailed = $this->emailNotification($n->fresh('poll.options'));   // toda comunicação vai por e-mail tb
        return response()->json(['data' => array_merge($n->fresh('poll.options')->toArray(), ['emailed' => $emailed])], 201);
    }

    /**
     * Regra de expiração: vazio → hoje 23:59:59; nunca aceita data no passado (422).
     * (O front já manda "hoje 23:59" no fuso do usuário convertido p/ UTC; aqui é a rede de segurança.)
     */
    private function normalizeExpiry($value): \Illuminate\Support\Carbon
    {
        if (empty($value)) return now()->endOfDay();
        $dt = \Illuminate\Support\Carbon::parse($value);
        abort_if($dt->isPast(), 422, 'A data de expiração não pode ser no passado.');
        return $dt;
    }

    /** Cria/atualiza a enquete vinculada à notificação. Substitui as opções só se ainda não houver votos. */
    private function savePoll(AppNotification $n, array $p): void
    {
        $poll = \App\Models\NotificationPoll::updateOrCreate(
            ['notification_id' => $n->id],
            [
                'question'          => (string) ($p['question'] ?? ''),
                'multiple_choice'   => (bool) ($p['multiple_choice'] ?? false),
                'allow_change_vote' => (bool) ($p['allow_change_vote'] ?? true),
                'show_results'      => (bool) ($p['show_results'] ?? true),
                'expires_at'        => $p['expires_at'] ?? $n->expires_at,
            ]
        );

        $options = array_values(array_filter(array_map('trim', $p['options'] ?? []), fn ($s) => $s !== ''));
        if (empty($options)) return;

        $hasVotes = \App\Models\NotificationPollVote::where('poll_id', $poll->id)->exists();
        if ($hasVotes) return; // preserva integridade dos votos — não recria opções

        $poll->options()->delete();
        foreach ($options as $i => $label) {
            $poll->options()->create(['label' => $label, 'order' => $i]);
        }
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        $isPoll = $request->input('type') === 'poll';
        return $request->validate([
            'title'        => ($creating ? 'required' : 'sometimes') . '|string|max:200',
            // Em enquete a mensagem é opcional (a pergunta é o conteúdo principal).
            'message'      => ($creating && !$isPoll ? 'required' : 'nullable') . '|string',
            'type'         => ($creating ? 'required' : 'sometimes') . '|in:' . implode(',', AppNotification::TYPES),
            'priority'     => ($creating ? 'required' : 'sometimes') . '|in:' . implode(',', AppNotification::PRIORITIES),
            'target_roles'          => 'nullable|array',
            'target_users'          => 'nullable|array',
            'target_contract_types' => 'nullable|array',
            'target_customer_id'    => 'nullable|exists:customers,id',
            'target_customer_ids'   => 'nullable|array',
            'target_customer_ids.*' => 'integer|exists:customers,id',
            'send_email'            => 'nullable|boolean',
            'visible'               => 'nullable|boolean',
            'recurrence'            => 'nullable|in:' . implode(',', AppNotification::RECURRENCES),
            'recurrence_value'      => 'nullable|integer|min:1|max:744',
            'is_template'           => 'nullable|boolean',
            'template_name'         => 'nullable|string|max:120',
            'requires_ack' => 'nullable|boolean',
            'cta_label'    => 'nullable|string|max:60',
            'cta_url'      => 'nullable|string|max:500',
            // Botões de decisão personalizados (nomes definidos pelo admin) — exige resposta.
            'actions'      => 'nullable|array|max:6',
            'actions.*'    => 'string|max:80',
            'version'      => 'nullable|integer|min:1',
            'expires_at'   => 'nullable|date',
            // Enquete (quando type=poll)
            'poll'                    => ($creating && $isPoll ? 'required' : 'nullable') . '|array',
            'poll.question'           => ($creating && $isPoll ? 'required' : 'nullable') . '|string|max:300',
            'poll.multiple_choice'    => 'nullable|boolean',
            'poll.allow_change_vote'  => 'nullable|boolean',
            'poll.show_results'       => 'nullable|boolean',
            'poll.expires_at'         => 'nullable|date',
            'poll.options'            => ($creating && $isPoll ? 'required' : 'nullable') . '|array|min:2',
            'poll.options.*'          => 'string|max:200',
        ]);
    }

    /** Todos os e-mails dos destinatários (perfis + usuários + contratação + cliente). */
    private function resolveRecipientEmails(AppNotification $n): array
    {
        $customerIds = array_filter(array_merge(
            (array) ($n->target_customer_ids ?? []),
            $n->target_customer_id ? [$n->target_customer_id] : []
        ));
        $hasTarget = $n->target_roles || $n->target_users || $n->target_contract_types || $customerIds;
        if (!$hasTarget) return [];
        return \App\Models\User::query()->whereNotNull('email')
            ->where(function ($w) use ($n, $customerIds) {
                if ($n->target_roles)          $w->orWhereIn('type', $n->target_roles);
                if ($n->target_users)          $w->orWhereIn('id', $n->target_users);
                if ($n->target_contract_types) $w->orWhereIn('contract_type', $n->target_contract_types);
                if ($customerIds)              $w->orWhereIn('customer_id', $customerIds);
            })
            ->pluck('email')->map(fn ($e) => mb_strtolower(trim($e)))->filter()->unique()->values()->all();
    }

    /**
     * Envia a notificação por e-mail com layout institucional. Recorrência reusa isto (reenvia a cada disparo).
     * $extraBcc = destinatários extras vindos da Central de Workflows (cópias configuradas pelo admin).
     */
    private function emailNotification(AppNotification $n, array $extraBcc = []): int
    {
        if ($n->is_template) return 0;  // modelo não dispara nada
        if (!$n->send_email || !\App\Services\GraphMailSender::enabled()) return 0;
        // Notificações saem do NOREPLY (config do mailbox Graph → MAIL_FROM_ADDRESS=noreply); HelpDeskEmailAccount só fallback.
        $from = config('services.graph.mailbox', env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS')))
            ?: \App\Models\HelpDeskEmailAccount::where('provider', 'microsoft365')->where('enabled', true)->orderBy('id')->value('email');
        if (!$from) return 0;

        $inlineBase = \App\Services\HelpDeskMailComposer::inlineAssetsSimple();

        // COM botões de decisão: cada destinatário recebe o e-mail com SEUS botões de 1 clique (link assinado).
        if (!empty($n->actions)) {
            $users = $this->resolveRecipientUsers($n)->filter(fn ($u) => filled($u->email));
            $sent = 0;
            foreach ($users as $u) {
                $body = $this->emailBody($n) . $this->actionButtonsHtml($n, $u);
                $html = \App\Services\HelpDeskMailComposer::composeSimple($n->title, $body);
                [$html, $imgAtts] = \App\Services\HelpDeskMailComposer::inlineImages($html);
                \App\Services\GraphMailSender::sendAs($from, [$u->email], [], $n->title, $html, [], array_merge($inlineBase, $imgAtts), false, []);
                $sent++;
            }
            return $sent;
        }

        // SEM botões: 1 disparo em BCC (comportamento padrão) + cópias da Central de Workflows.
        $emails = array_values(array_unique(array_merge(
            $this->resolveRecipientEmails($n),
            array_map(fn ($e) => mb_strtolower(trim((string) $e)), $extraBcc)
        )));
        if (empty($emails)) return 0;
        $html = \App\Services\HelpDeskMailComposer::composeSimple($n->title, $this->emailBody($n));
        [$html, $imgAtts] = \App\Services\HelpDeskMailComposer::inlineImages($html);
        \App\Services\GraphMailSender::sendAs($from, [$from], [], $n->title, $html, [], array_merge($inlineBase, $imgAtts), false, $emails);
        return count($emails);
    }

    /** Botões de decisão (1 clique) no e-mail — link assinado POR usuário, válido até o prazo da decisão. */
    private function actionButtonsHtml(AppNotification $n, \App\Models\User $u): string
    {
        $actions = array_values(array_filter(array_map(fn ($a) => trim((string) $a), (array) ($n->actions ?? [])), fn ($a) => $a !== ''));
        if (empty($actions)) return '';
        $expires = $n->expires_at && $n->expires_at->isFuture() ? $n->expires_at : now()->endOfDay();

        $btns = '';
        foreach ($actions as $i => $a) {
            $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'notifications.respond-email', $expires,
                ['notification' => $n->id, 'action' => $a, 'user' => $u->id]
            );
            $bg = $i === 0 ? '#06b6d4' : '#374151';   // 1º botão em destaque (ex.: confirmação)
            $btns .= '<a href="' . e($url) . '" style="display:inline-block;margin:4px 8px 4px 0;padding:11px 22px;'
                . 'background:' . $bg . ';color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px">'
                . e($a) . '</a>';
        }
        $prazo = $expires->locale('pt_BR')->isoFormat('DD/MM HH:mm');
        return '<div style="margin:20px 0 4px">' . $btns . '</div>'
            . '<p style="margin:8px 0 0;font-size:12px;color:#6b7280">Clique em uma opção para registrar sua resposta. Prazo: ' . e($prazo) . '.</p>';
    }

    /**
     * Resposta de 1 CLIQUE vinda do e-mail (link assinado, sem login). Registra a decisão do usuário
     * (igual ao respond da Central) e devolve uma página de confirmação simples.
     */
    public function respondEmail(Request $request, AppNotification $notification)
    {
        $u       = \App\Models\User::find((int) $request->query('user'));
        $action  = (string) $request->query('action');
        $valid   = $u && in_array($action, (array) ($notification->actions ?? []), true);
        $expired = $notification->expires_at && $notification->expires_at->isPast();

        // Já respondeu? O link do e-mail é de uso único — não regrava nem permite trocar por aqui.
        $existing = ($u ? NotificationRead::where('notification_id', $notification->id)->where('user_id', $u->id)->first() : null);
        $already  = $existing && $existing->response_action;

        if ($valid && !$expired && !$already) {
            $r = $existing ?: new NotificationRead(['notification_id' => $notification->id, 'user_id' => $u->id]);
            $r->viewed_at       = $r->viewed_at ?? now();
            $r->response_action = $action;
            $r->ack_at          = now();                       // respondeu → sai de pendente (pop-up não reabre)
            $r->acked_version   = $notification->version;
            $r->ack_ip          = $request->ip();
            $r->ack_user_agent  = (string) $request->userAgent();
            $r->save();
        }

        // Estado da página: ok | já-respondida | prazo encerrado | link inválido.
        if (!$valid)        { $ok = false; $msg = 'Não foi possível registrar a resposta (link inválido).'; }
        elseif ($already)   { $ok = false; $msg = 'Você já respondeu <b>“' . e($existing->response_action) . '”</b> a esta solicitação. Para alterar, acesse a Central de Notificações no Minutor.'; }
        elseif ($expired)   { $ok = false; $msg = 'O prazo para responder já encerrou.'; }
        else                { $ok = true;  $msg = 'Sua resposta <b>“' . e($action) . '”</b> foi registrada. Obrigado!'; }

        $title = e($notification->title);
        $color = $ok ? '#06b6d4' : '#b91c1c';
        // Já respondida → título "Pergunta já respondida" em vermelho; demais estados mantêm o título da notificação.
        // <font color> além do style: alguns clientes/sanitizadores descartam CSS inline mas preservam o atributo.
        $heading      = $already ? 'Pergunta já respondida' : $title;
        $headingColor = $already ? '#e11d2a' : '#fff';
        $headingHtml  = $already ? '<font color="#e11d2a">' . $heading . '</font>' : $heading;
        $page = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $heading . '</title></head>'
            . '<body style="margin:0;font-family:Segoe UI,Arial,sans-serif;background:#0b0f14;color:#e5e7eb;display:flex;min-height:100vh;align-items:center;justify-content:center">'
            . '<div style="max-width:440px;padding:32px;text-align:center;background:#111827;border:1px solid #1f2937;border-radius:16px">'
            . '<div style="font-size:34px;margin-bottom:8px;color:' . $color . '">' . ($ok ? '&#10003;' : '&#9888;') . '</div>'
            . '<h1 style="font-size:18px;margin:0 0 6px;color:' . $headingColor . '">' . $headingHtml . '</h1>'
            . '<p style="font-size:14px;color:#9ca3af;margin:0">' . $msg . '</p>'
            . '<p style="font-size:12px;color:#6b7280;margin:18px 0 0">Você já pode fechar esta janela.</p>'
            . '</div></body></html>';
        return response($page, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Corpo do e-mail: mensagem da notificação ou, em enquete, pergunta + opções + chamada p/ votar. */
    private function emailBody(AppNotification $n, ?array $pollPayload = null): string
    {
        if ($n->type !== 'poll') return (string) $n->message;

        $poll = $pollPayload ?? ($n->relationLoaded('poll') ? optional($n->poll)->toArray() : null);
        $question = trim((string) ($poll['question'] ?? ''));
        $options  = $pollPayload
            ? array_values(array_filter(array_map('trim', $pollPayload['options'] ?? []), fn ($s) => $s !== ''))
            : ($n->poll ? $n->poll->options->pluck('label')->all() : []);

        $lis = '';
        foreach ($options as $o) $lis .= '<li style="margin:3px 0">' . e($o) . '</li>';
        $msg = trim((string) $n->message);
        return ($msg !== '' ? '<p style="margin:0 0 10px">' . $msg . '</p>' : '')
            . ($question !== '' ? '<p style="margin:0 0 8px;font-weight:600">' . e($question) . '</p>' : '')
            . ($lis !== '' ? '<ul style="margin:0 0 12px;padding-left:18px">' . $lis . '</ul>' : '')
            . '<p style="margin:12px 0 0;font-size:13px;color:#6b7280">Acesse a Central de Notificações no Minutor para registrar seu voto.</p>';
    }

    /** Prévia do e-mail (layout institucional) + nº de destinatários. */
    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $v = $this->validatePayload($request, true);
        $pollPayload = $v['poll'] ?? null;
        unset($v['poll']);
        $n = new AppNotification($v);
        $html = \App\Services\HelpDeskMailComposer::composeSimple($n->title, $this->emailBody($n, $pollPayload), \App\Services\HelpDeskMailFooter::whiteLogoDataUri());
        return response()->json(['data' => ['html' => $html, 'recipients' => count($this->resolveRecipientEmails($n))]]);
    }

    /** Metadados p/ o form: tipos de contratação + clientes (select). */
    public function meta(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        return response()->json(['data' => [
            'contract_types' => [['id' => 'clt', 'name' => 'CLT'], ['id' => 'cooperado', 'name' => 'Cooperado'], ['id' => 'pj', 'name' => 'PJ']],
            'customers'      => \App\Models\Customer::orderBy('name')->limit(1000)->get(['id', 'name']),
        ]]);
    }

    /** Busca de usuários p/ destinatários específicos. */
    public function searchUsers(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $q = trim((string) $request->query('search', ''));
        $rows = \App\Models\User::query()->whereNotNull('email')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'email', 'type']);
        return response()->json(['data' => $rows]);
    }
}
