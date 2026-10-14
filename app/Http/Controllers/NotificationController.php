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
    /** Perfis que podem publicar/gerir publicações internas (avisos, enquetes, presença). */
    private const MANAGERS = ['admin', 'administrativo', 'coordenador'];

    // Conclusões de tarefa (auto): SENSÍVEL (reunião) — na tela de gerenciar só p/ destinatário.
    private const COMPLETION_TITLES = ['Tarefa de reunião concluída', 'Tarefa concluída', 'Tarefa resolvida pela coordenação'];
    // Notificações PESSOAIS/automáticas (apontamento, comentário de projeto): NÃO são publicações —
    // fora do gerenciamento p/ qualquer perfil.
    private const APONTAMENTO_TITLES = ['Apontamento rejeitado', 'Ajuste solicitado no apontamento', 'Novo comentário no projeto'];

    private function internalOrAbort(Request $request): \App\Models\User
    {
        $u = $request->user();
        abort_if(!$u || $u->type === 'cliente' || !in_array($u->type, self::INTERNAL, true), 403, 'Central de Notificações disponível apenas para usuários internos.');
        return $u;
    }

    /** Pode publicar/gerenciar publicações (admin, coordenador, administrativo). */
    private function canManage(?\App\Models\User $u): bool
    {
        return $u && in_array($u->type, self::MANAGERS, true);
    }

    /** Admin e ADMINISTRATIVO gerenciam qualquer publicação; coordenador só as que criou. */
    private function authorizeOwnOrAdmin(?\App\Models\User $u, AppNotification $n): void
    {
        abort_unless($this->canManage($u), 403);
        abort_if(!$u->isAdmin() && !$u->isAdministrativo() && (int) $n->created_by !== (int) $u->id, 403, 'Você só pode gerenciar as publicações que criou.');
    }

    /** Notificações visíveis ao usuário + estado de leitura/aceite + flags computadas. */
    public function index(Request $request): JsonResponse
    {
        $u = $this->internalOrAbort($request);

        $rows = $this->visibleQuery($u)
            ->with(['poll.options', 'survey.questions.options'])
            ->orderByRaw("case priority when 'critical' then 4 when 'high' then 3 when 'medium' then 2 else 1 end desc")
            ->orderByDesc('created_at')
            ->get();

        // LEMBRETES DE AÇÃO (recorrência): esconder de quem NÃO tem mais pendência — mesmo entre
        // disparos. Com "qualquer coordenador aprova", um colega pode ter resolvido; quem não está
        // mais nos "afetados" da regra não deve mais ver o pop-up/Meu Dia (nem no e-mail já enviado).
        $reminderMap = \App\Models\ActionReminderRule::whereNotNull('notification_id')
            ->pluck('key', 'notification_id'); // [notification_id => rule_key]
        if ($reminderMap->isNotEmpty()) {
            $affectedCache = [];
            $rows = $rows->reject(function (AppNotification $n) use ($reminderMap, $u, &$affectedCache) {
                $ruleKey = $reminderMap->get($n->id);
                if (!$ruleKey) return false; // não é lembrete de recorrência → mantém
                if (!array_key_exists($ruleKey, $affectedCache)) {
                    $affectedCache[$ruleKey] = \Illuminate\Support\Facades\Cache::remember(
                        "action_reminder_affected_{$ruleKey}", 60,
                        fn () => \App\Http\Controllers\ActionReminderController::affectedUserIds($ruleKey)
                    );
                }
                return !in_array((int) $u->id, array_map('intval', $affectedCache[$ruleKey]), true);
            })->values();
        }

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
            $survey = $n->type === 'survey' && $n->survey
                ? \App\Http\Controllers\NotificationSurveyController::formPayload($n->survey, $u)
                : null;
            return array_merge($n->toArray(), [
                'viewed'      => $viewed,
                'acked'       => $acked,
                'pending_ack' => $n->requires_ack && !$acked,
                'ack_at'      => $r?->ack_at,
                'my_response' => $r?->response_action,   // botão de decisão que o usuário clicou
                // Pode indicar acompanhante "Outro" (além da família)? (está na lista autorizada)
                'can_invite_other' => (bool) $n->allow_guests
                    && in_array((int) $u->id, array_map('intval', (array) ($n->guest_other_user_ids ?? [])), true),
                'poll'        => $poll,
                'survey'      => $survey,
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

        // Polling de alta frequência (menu). Cache por-usuário de 15s alivia drasticamente a carga
        // de DB no pico de fechamento (contagens repetidas a cada poll × muitos usuários).
        $data = \Illuminate\Support\Facades\Cache::remember("badges:{$u->id}", 15, function () use ($u) {
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

        // Apontamentos aguardando liberação (chegaram pela integração em competência
        // já fechada) — sinaliza no menu "Atrasos (integração)". Mesmo conjunto da tela.
        $atrasos = \App\Models\Timesheet::where('status', \App\Models\Timesheet::STATUS_LATE)
            ->whereNull('deleted_at')->count();

        return [
            'pending_tasks'        => $pendingTasks,
            'overdue_tasks'        => $overdueTasks,
            'unread_notifications' => $unread,
            'atrasos'              => $atrasos,
            'critical'             => $overdueTasks > 0,
        ];
        });

        return response()->json(['data' => $data]);
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
                ->when($u->work_bond, fn ($w) => $w->orWhereJsonContains('target_bonds', $u->work_bond))
                ->when($u->customer_id, fn ($w) => $w
                    ->orWhere('target_customer_id', $u->customer_id)
                    ->orWhereJsonContains('target_customer_ids', $u->customer_id)))
            // Retirado do envio: não vê a notificação mesmo caindo num grupo-alvo.
            ->where(fn ($q) => $q->whereNull('excluded_user_ids')->orWhereJsonDoesntContain('excluded_user_ids', $u->id))
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
            // Chat: linha de base do maior id de mensagem visível ao usuário (exceto as dele).
            $lastMsg = $this->maxInboxMessageId($u);
            $deadline = time() + 30;
            while (time() < $deadline) {
                if (connection_aborted()) break;
                $emitted = false;

                $max = (int) $this->visibleQuery($u)->max('id');
                if ($max > $last) {
                    $last = $max;
                    echo "id: {$max}\n";
                    echo "event: notify\n";
                    echo 'data: {"max":' . $max . "}\n\n";
                    $emitted = true;
                }

                // Mensagem de chat nova (id maior) → evento "inbox" SEM linha "id:"
                // (não pode mexer no Last-Event-ID das notificações).
                $maxMsg = $this->maxInboxMessageId($u);
                if ($maxMsg > $lastMsg) {
                    $lastMsg = $maxMsg;
                    echo "event: inbox\n";
                    echo 'data: {"max":' . $maxMsg . "}\n\n";
                    $emitted = true;
                }

                if (! $emitted) {
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

    /**
     * Maior id de mensagem de chat visível ao usuário, ignorando as que ele mesmo enviou.
     * Mensagens do BOT (sender_user_id NULL) contam. Usado pelo stream SSE (evento "inbox").
     */
    private function maxInboxMessageId(\App\Models\User $u): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('messages as m')
            ->join('conversation_participants as p', 'p.conversation_id', '=', 'm.conversation_id')
            ->where('p.user_id', $u->id)
            ->whereNull('m.deleted_at')
            ->where(function ($q) use ($u) {
                $q->whereNull('m.sender_user_id')->orWhere('m.sender_user_id', '!=', $u->id);
            })
            ->max('m.id');
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
        $r->response_source = 'app';                        // respondeu pela Central/pop-up
        $r->ack_at          = now();                       // respondeu → não fica mais pendente
        $r->acked_version   = $notification->version;
        $r->ack_ip          = $request->ip();
        $r->ack_user_agent  = (string) $request->userAgent();
        $r->save();

        // Acompanhantes/familiares: só quando a notificação permite E a resposta é AFIRMATIVA
        // (a 1ª ação = "Confirmo presença"). Regrava a lista do respondente (idempotente ao
        // reconfirmar). Resposta negativa (ou allow_guests off) limpa eventuais dependentes.
        if ($notification->allow_guests) {
            \App\Models\NotificationGuest::where('notification_id', $notification->id)->where('user_id', $u->id)->delete();
            $affirmative = isset($actions[0]) && $action === $actions[0];
            if ($affirmative) {
                $guests = $this->parseGuests($request->input('guests', []), $notification, $u->id);
                if ($guests) \App\Models\NotificationGuest::insert($guests);
            }
        }
        return response()->json(['data' => ['responded' => true, 'action' => $action]]);
    }

    /**
     * LOG de uma comunicação (admin): destinatários × visualizou/quando × resposta do botão.
     * Serve p/ "log de visualização de tudo que foi enviado" e "resultado dos botões de decisão".
     */
    public function log(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorizeOwnOrAdmin($request->user(), $notification);
        // Inclui os convites CANCELADOS (excluded_user_ids) p/ exibi-los marcados no Log (com opção de reativar).
        $recipients = $this->resolveRecipientUsers($notification, true);
        $cancelledIds = array_map('intval', (array) ($notification->excluded_user_ids ?? []));
        $otherIds     = array_map('intval', (array) ($notification->guest_other_user_ids ?? []));
        $reads = NotificationRead::where('notification_id', $notification->id)->get()->keyBy('user_id');
        // Acompanhantes/familiares por respondente (só quando a notificação permite).
        $guestsByUser = $notification->allow_guests
            ? \App\Models\NotificationGuest::where('notification_id', $notification->id)->get()->groupBy('user_id')
            : collect();

        $rows = $recipients->map(function ($usr) use ($reads, $guestsByUser, $cancelledIds, $otherIds) {
            $r = $reads->get($usr->id);
            $cancelled = in_array((int) $usr->id, $cancelledIds, true);
            return [
                'user_id'      => $usr->id,
                'user_name'    => $usr->name,
                'user_email'   => $usr->email,
                'cancelled'    => $cancelled, // convite cancelado (não recebe)
                'can_invite_other' => in_array((int) $usr->id, $otherIds, true), // pode indicar "Outro"
                'viewed_at'    => $cancelled ? null : $r?->viewed_at?->toIso8601String(),
                'response'     => $cancelled ? null : $r?->response_action,
                'response_source' => (!$cancelled && $r && $r->response_action) ? ($r->response_source ?: null) : null, // 'app' | 'email' | null
                'responded_at' => (!$cancelled && $r && $r->response_action) ? $r->ack_at?->toIso8601String() : null,
                'guests'       => $cancelled ? collect() : ($guestsByUser->get($usr->id) ?? collect())
                    ->map(fn ($g) => ['nome' => $g->nome, 'parentesco' => $g->parentesco, 'idade' => $g->idade])->values(),
            ];
        })->sortBy('user_name')->values();

        // Contadores desconsideram os convites cancelados.
        $active = $rows->where('cancelled', false);
        $actions = (array) ($notification->actions ?? []);
        $byAction = [];
        foreach ($actions as $a) $byAction[$a] = $active->where('response', $a)->count();

        return response()->json(['data' => [
            'recipients' => $rows,
            'summary'    => [
                'total'          => $active->count(),
                'viewed'         => $active->whereNotNull('viewed_at')->count(),
                'responded'      => $active->whereNotNull('response')->count(),
                'cancelled'      => $rows->where('cancelled', true)->count(),
                'actions'        => $actions,
                'by_action'      => $byAction,
                'allow_guests'   => (bool) $notification->allow_guests,
                'guests_total'   => $active->sum(fn ($x) => count($x['guests'])),
            ],
        ]]);
    }

    /**
     * REVOGA a resposta de UM destinatário: limpa decisão/ack (e acompanhantes) → ele volta a
     * ficar PENDENTE (pop-up reabre + volta a receber o e-mail nas recorrências). Reenvia o
     * e-mail de decisão na hora para esse usuário (best-effort) quando a publicação envia e-mail.
     */
    public function revoke(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorizeOwnOrAdmin($request->user(), $notification);
        $data = $request->validate([
            'user_id'          => 'required|integer|exists:users,id',
            'can_invite_other' => 'nullable|boolean', // define se pode indicar "Outro" ao reconvidar
        ]);
        $uid  = (int) $data['user_id'];

        $read = NotificationRead::where('notification_id', $notification->id)->where('user_id', $uid)->first();
        if ($read) {
            $read->response_action = null;
            $read->response_source = null;
            $read->ack_at          = null;   // volta a pendente → pop-up reabre e recebe de novo
            $read->acked_version   = null;
            $read->save();
        }
        // Remove os acompanhantes que havia informado.
        \App\Models\NotificationGuest::where('notification_id', $notification->id)->where('user_id', $uid)->delete();
        // Se o convite estava CANCELADO, reativa (tira de excluded_user_ids) — revogar = re-convidar.
        $this->setInviteExcluded($notification, $uid, false);
        // "Pode convidar além da família?" (perguntado no modal de revogar) → liga/desliga "Outro".
        if ($request->has('can_invite_other')) {
            $this->setGuestOther($notification, $uid, $request->boolean('can_invite_other'));
        }

        // "Volta a receber": reenvia o e-mail de decisão só para esse usuário (não quebra se falhar).
        $resent = false;
        $user = \App\Models\User::find($uid);
        if ($user) {
            try { $resent = $this->sendActionEmail($notification, $user); } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Falha ao reenviar e-mail após revogar', [
                    'notification_id' => $notification->id, 'user_id' => $uid, 'erro' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['data' => ['revoked' => true, 'user_id' => $uid, 'email_resent' => $resent]]);
    }

    /**
     * CANCELA o convite de UM destinatário: entra em excluded_user_ids → NÃO recebe mais
     * (sai do pop-up, do e-mail e da apuração). Limpa decisão/ack e acompanhantes. Para
     * reativar (voltar a receber), use "revogar". `cancel=false` reativa.
     */
    public function cancelInvite(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorizeOwnOrAdmin($request->user(), $notification);
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'cancel'  => 'nullable|boolean',
        ]);
        $uid    = (int) $data['user_id'];
        $cancel = $request->boolean('cancel', true);

        $this->setInviteExcluded($notification, $uid, $cancel);

        if ($cancel) {
            // Cancelado não conta mais: limpa a resposta/ack e os acompanhantes.
            $read = NotificationRead::where('notification_id', $notification->id)->where('user_id', $uid)->first();
            if ($read) {
                $read->response_action = null;
                $read->response_source = null;
                $read->ack_at          = null;
                $read->acked_version   = null;
                $read->save();
            }
            \App\Models\NotificationGuest::where('notification_id', $notification->id)->where('user_id', $uid)->delete();
        }

        return response()->json(['data' => ['cancelled' => $cancel, 'user_id' => $uid]]);
    }

    /** Liga/desliga a presença de um usuário em notifications_center.excluded_user_ids. */
    private function setInviteExcluded(AppNotification $n, int $uid, bool $excluded): void
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($n->excluded_user_ids ?? []))));
        $has = in_array($uid, $ids, true);
        if ($excluded && !$has) {
            $ids[] = $uid;
        } elseif (!$excluded && $has) {
            $ids = array_values(array_filter($ids, fn ($i) => $i !== $uid));
        } else {
            return; // já está no estado desejado
        }
        $n->excluded_user_ids = $ids ?: null;
        $n->save();
    }

    /** Liga/desliga a permissão de indicar "Outro" (guest_other_user_ids) p/ um usuário. */
    private function setGuestOther(AppNotification $n, int $uid, bool $allowed): void
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($n->guest_other_user_ids ?? []))));
        $has = in_array($uid, $ids, true);
        if ($allowed && !$has) {
            $ids[] = $uid;
        } elseif (!$allowed && $has) {
            $ids = array_values(array_filter($ids, fn ($i) => $i !== $uid));
        } else {
            return;
        }
        $n->guest_other_user_ids = $ids ?: null;
        $n->save();
    }

    /**
     * Normaliza a lista de acompanhantes (pop-up ou e-mail). Regras:
     * - idade mantida p/ Filho(a) e p/ "Outro" (quando criança); zerada p/ Cônjuge.
     * - "Outro" só aceito de quem pode indicar além da família (guest_other_user_ids); senão descarta.
     * - nome vazio descartado; máx. 30. (Obrigatoriedade de idade é validada no FE.)
     */
    private function parseGuests($raw, AppNotification $n, int $uid): array
    {
        $canOther = in_array($uid, array_map('intval', (array) ($n->guest_other_user_ids ?? [])), true);
        return collect((array) $raw)
            ->map(function ($g) {
                $nome  = trim((string) (is_array($g) ? ($g['nome'] ?? '') : ''));
                $par   = trim((string) (is_array($g) ? ($g['parentesco'] ?? '') : ''));
                $idade = is_array($g) && isset($g['idade']) && $g['idade'] !== '' ? (int) $g['idade'] : null;
                $isOutro = stripos($par, 'outro') !== false;
                $isFilho = stripos($par, 'filho') !== false;
                if (!$isFilho && !$isOutro) $idade = null; // só Filho(a)/Outro têm idade
                return ['nome' => $nome, 'parentesco' => $par, 'idade' => $idade, 'is_outro' => $isOutro];
            })
            ->filter(fn ($g) => $g['nome'] !== '')
            ->reject(fn ($g) => $g['is_outro'] && !$canOther) // "Outro" só de quem é autorizado
            ->take(30)
            ->map(fn ($g) => [
                'notification_id' => $n->id, 'user_id' => $uid,
                'nome' => $g['nome'], 'parentesco' => $g['parentesco'] !== '' ? $g['parentesco'] : null,
                'idade' => $g['idade'],
                'created_at' => now(), 'updated_at' => now(),
            ])->values()->all();
    }

    /** Envia o e-mail de decisão (com botões de 1 clique) para UM usuário. Retorna se enviou. */
    private function sendActionEmail(AppNotification $n, \App\Models\User $u): bool
    {
        if (!$n->send_email || !\App\Services\GraphMailSender::enabled() || empty($n->actions) || !filled($u->email)) {
            return false;
        }
        $from = config('services.graph.mailbox', env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS')))
            ?: \App\Models\HelpDeskEmailAccount::where('provider', 'microsoft365')->where('enabled', true)->orderBy('id')->value('email');
        if (!$from) {
            return false;
        }
        $inlineBase = \App\Services\HelpDeskMailComposer::inlineAssetsSimple();
        $body = $this->emailBody($n) . $this->actionButtonsHtml($n, $u);
        $html = \App\Services\HelpDeskMailComposer::composeSimple($n->title, $body);
        [$html, $imgAtts] = \App\Services\HelpDeskMailComposer::inlineImages($html);
        [$ok] = \App\Services\GraphMailSender::sendAs($from, [$u->email], [], $n->title, $html, [], array_merge($inlineBase, $imgAtts), false, []);
        return (bool) $ok;
    }

    /** Usuários destinatários (perfis + usuários + contratação + cliente) — base do LOG. */
    private function resolveRecipientUsers(AppNotification $n, bool $includeExcluded = false)
    {
        $customerIds = array_filter(array_merge(
            (array) ($n->target_customer_ids ?? []),
            $n->target_customer_id ? [$n->target_customer_id] : []
        ));
        $hasTarget = $n->target_roles || $n->target_users || $n->target_contract_types || $n->target_bonds || $customerIds;
        if (!$hasTarget) return collect();
        return \App\Models\User::query()
            ->where(function ($w) use ($n, $customerIds) {
                if ($n->target_roles)          $w->orWhereIn('type', $n->target_roles);
                if ($n->target_users)          $w->orWhereIn('id', $n->target_users);
                if ($n->target_contract_types) $w->orWhereIn('contract_type', $n->target_contract_types);
                if ($n->target_bonds)          $w->orWhereIn('work_bond', $n->target_bonds);
                if ($customerIds)              $w->orWhereIn('customer_id', $customerIds);
            })
            // Convites cancelados (excluded_user_ids) saem do envio; o Log pede includeExcluded p/ exibi-los marcados.
            ->when($n->excluded_user_ids && !$includeExcluded, fn ($q) => $q->whereNotIn('id', $n->excluded_user_ids))
            ->get(['id', 'name', 'email']);
    }

    /** Lista TODAS as notificações p/ gestão (admin) + nº de aceites. */
    public function manage(Request $request): JsonResponse
    {
        $admin = $request->user();
        abort_unless($this->canManage($admin), 403);
        // Avisos AUTO-GERADOS pelos lembretes de ação têm bloco próprio na Central — não duplicar na lista.
        $reminderIds = \App\Models\ActionReminderRule::whereNotNull('notification_id')->pluck('notification_id')->all();
        $rows = AppNotification::withCount(['reads as acks_count' => fn ($q) => $q->whereNotNull('ack_at')])
            ->with('poll.options')
            ->when($reminderIds, fn ($q) => $q->whereNotIn('id', $reminderIds))
            ->where(function ($q) use ($admin) {
                // PUBLICAÇÕES (avisos/comunicados/enquetes): admin e ADMINISTRATIVO gerenciam TODAS;
                // coordenador só as que criou.
                $q->where(function ($pub) use ($admin) {
                    // Fora do gerenciamento: conclusões de tarefa E notificações de apontamento (pessoais).
                    $pub->whereNotIn('title', array_merge(self::COMPLETION_TITLES, self::APONTAMENTO_TITLES));
                    // Fora TAMBÉM: avisos AUTO-GERADOS por rotinas (competências, contratação/parceiro,
                    // apontamento, despesas, aprovações, tarefas atrasadas, fechamento…). Todas essas têm
                    // workflow próprio na Central de Workflows e SEMPRE trazem um cta_url (link da ação);
                    // publicações criadas AQUI não têm cta_url. O lembrete recorrente de fechamento semanal
                    // é o único sem cta_url → filtrado por título. Esta tela só lista publicações manuais.
                    $pub->whereNot(function ($wf) {
                        $wf->where(fn ($c) => $c->whereNotNull('cta_url')->where('cta_url', '<>', ''))
                           ->orWhere('title', 'like', '%Fechamento semanal de horas%');
                    });
                    if (!$admin->isAdmin() && !$admin->isAdministrativo()) {
                        $pub->where('created_by', $admin->id);
                    }
                })
                // 🔒 CONCLUSÕES de tarefa (auto-geradas, SENSÍVEL — reunião): só aparecem p/ quem é
                // DESTINATÁRIO (participante/criador da tarefa), ou seja, tem notification_reads. NUNCA
                // p/ quem não participa — nem admin/administrativo. (pedido Ricardo: reunião só p/ envolvidos)
                ->orWhere(function ($done) use ($admin) {
                    $done->whereIn('title', self::COMPLETION_TITLES)
                        ->whereHas('reads', fn ($r) => $r->where('user_id', $admin->id));
                });
            })
            ->orderByDesc('created_at')->get()
            ->map(function (AppNotification $n) use ($admin) {
                $arr = $n->toArray();
                $arr['poll'] = $n->type === 'poll' && $n->poll
                    ? \App\Http\Controllers\NotificationPollController::results($n->poll, $admin)
                    : null;
                // Pesquisa: estrutura p/ hidratar o editor (labels) + flag de respostas.
                $arr['survey'] = null;
                if ($n->type === 'survey' && $n->survey) {
                    $n->survey->loadMissing('questions.options');
                    $arr['survey'] = [
                        'survey_id'  => $n->survey->id,
                        'anonymous'  => (bool) $n->survey->anonymous,
                        'has_responses' => \App\Models\NotificationSurveyResponse::where('survey_id', $n->survey->id)->exists(),
                        'questions'  => $n->survey->questions->map(fn ($q) => [
                            'id' => $q->id, 'label' => $q->label, 'type' => $q->type,
                            'required' => (bool) $q->required, 'multiple' => (bool) $q->multiple,
                            'options' => $q->options->pluck('label')->values(),
                        ])->values(),
                    ];
                }
                return $arr;
            });
        return response()->json(['data' => $rows]);
    }

    public function update(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorizeOwnOrAdmin($request->user(), $notification);
        $v = $this->validatePayload($request, false);
        $pollPayload = $v['poll'] ?? null;
        $surveyPayload = $v['survey'] ?? null;
        unset($v['poll'], $v['survey']);
        if (array_key_exists('type', $v)) $v['requires_ack'] = $v['type'] === 'require_ack' ? true : (bool) ($v['requires_ack'] ?? $notification->requires_ack);
        if (array_key_exists('actions', $v) && !empty($v['actions'])) $v['requires_ack'] = true;   // botões de decisão exigem resposta
        if (array_key_exists('expires_at', $v)) $v['expires_at'] = $this->normalizeExpiry($v['expires_at']);
        if (array_key_exists('message', $v)) $v['message'] = $v['message'] ?? '';  // coluna NOT NULL (enquete tem msg opcional)
        \Illuminate\Support\Facades\DB::transaction(function () use ($notification, $v, $pollPayload, $surveyPayload) {
            $notification->update($v);
            if ($notification->type === 'poll' && $pollPayload) $this->savePoll($notification, $pollPayload);
            if ($notification->type === 'survey' && $surveyPayload) $this->saveSurvey($notification, $surveyPayload);
        });
        return response()->json(['data' => $notification->fresh('poll.options')]);
    }

    public function destroy(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorizeOwnOrAdmin($request->user(), $notification);
        $notification->delete();
        return response()->json(null, 204);
    }

    /**
     * Encerra a campanha AGORA (admin/dono): desliga a recorrência e fecha o prazo da decisão.
     * Efeito: o pop-up some (sai do visibleQuery por expires_at), não re-pergunta mais
     * (recurrence=none sai do fire-recurring) e novas respostas ficam bloqueadas
     * ("prazo já encerrou"). NÃO apaga — mantém visível no Gerenciar com as respostas registradas.
     */
    public function encerrar(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorizeOwnOrAdmin($request->user(), $notification);
        $notification->forceFill([
            'recurrence'          => 'none',
            'recurrence_value'    => null,
            'recurrence_weekdays' => null,
            'expires_at'          => now(),
        ])->save();
        return response()->json(['data' => $notification->fresh('poll.options')]);
    }

    /** Reenvia o aviso AGORA (admin): reabre p/ todos (limpa leituras) + reenvia e-mail + re-popa. */
    public function resend(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorizeOwnOrAdmin($request->user(), $notification);
        abort_if($notification->is_template, 422, 'Modelos não podem ser reenviados — use "Usar modelo" para publicar.');
        // channel: 'popup' = só reabre o pop-up (sem e-mail); 'email_only' = só reenvia o e-mail
        // (NÃO reabre o pop-up nem zera leituras); qualquer outro (padrão) = e-mail + pop-up.
        $channel = $request->input('channel');
        $sendEmail   = $channel !== 'popup';
        $reopenPopup = $channel !== 'email_only';
        $emailed = $this->fire($notification, [], $sendEmail, $reopenPopup);
        return response()->json(['data' => array_merge($notification->fresh('poll.options')->toArray(), ['emailed' => $emailed])]);
    }

    /**
     * "Dispara" um aviso de novo: reabre p/ quem NÃO completou, estende a validade até o
     * fim do dia, marca resent_at (o pop-up reaparece) e reenvia o e-mail. Reusado pelo reenvio
     * manual e pela recorrência. Retorna nº de e-mails enviados.
     */
    public function fire(AppNotification $n, array $extraBcc = [], bool $sendEmail = true, bool $reopenPopup = true): int
    {
        if ($reopenPopup) {
            // Reabre SÓ para quem ainda não decidiu/leu. Quem já confirmou leitura (ack_at) OU
            // respondeu um botão de decisão (Confirmo presença / Não poderei ir — respond também
            // grava ack_at) NÃO é incomodado de novo: a decisão persiste entre recorrências.
            // Reads apenas "vistos" (viewed_at sem ack_at) são apagados → o usuário é re-perguntado.
            NotificationRead::where('notification_id', $n->id)->whereNull('ack_at')->delete();
            $n->forceFill([
                'resent_at'     => now(),
                'last_fired_at' => now(),
                'expires_at'    => $n->expires_at && $n->expires_at->isFuture() ? $n->expires_at : now()->endOfDay(),
            ])->save();
        } else {
            // Somente e-mail: NÃO reabre o pop-up nem zera leituras — só registra o disparo.
            $n->forceFill(['last_fired_at' => now()])->save();
        }
        // $sendEmail = false → só re-dispara o pop-up (zera leituras), sem reenviar e-mail.
        return $sendEmail ? $this->emailNotification($n->fresh('poll.options'), $extraBcc) : 0;
    }

    /** Cria uma notificação (admin). */
    public function store(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($this->canManage($u), 403, 'Apenas administradores, coordenadores e administrativos podem publicar.');
        $v = $this->validatePayload($request, true);
        $pollPayload = $v['poll'] ?? null;
        $surveyPayload = $v['survey'] ?? null;
        unset($v['poll'], $v['survey']);
        $v['requires_ack'] = ($v['type'] ?? '') === 'require_ack' ? true : (bool) ($v['requires_ack'] ?? false);
        if (!empty($v['actions'])) $v['requires_ack'] = true;   // botões de decisão exigem resposta
        $v['message'] = $v['message'] ?? '';
        $v['expires_at'] = $this->normalizeExpiry($v['expires_at'] ?? null);
        $v['created_by'] = $u->id;

        $n = \Illuminate\Support\Facades\DB::transaction(function () use ($v, $pollPayload, $surveyPayload) {
            $n = AppNotification::create($v);
            if (($v['type'] ?? '') === 'poll' && $pollPayload) {
                $this->savePoll($n, $pollPayload);
            }
            if (($v['type'] ?? '') === 'survey' && $surveyPayload) {
                $this->saveSurvey($n, $surveyPayload);
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

    /** Cria/atualiza a PESQUISA (survey) + perguntas/opções. Recria as perguntas só se ainda não houver respostas. */
    private function saveSurvey(AppNotification $n, array $p): void
    {
        $survey = \App\Models\NotificationSurvey::updateOrCreate(
            ['notification_id' => $n->id],
            [
                'anonymous'  => (bool) ($p['anonymous'] ?? false),
                'expires_at' => $p['expires_at'] ?? $n->expires_at,
            ]
        );

        $questions = array_values(array_filter((array) ($p['questions'] ?? []), fn ($q) => trim((string) ($q['label'] ?? '')) !== ''));
        if (empty($questions)) return;

        // Preserva integridade das respostas já dadas — não recria perguntas.
        if (\App\Models\NotificationSurveyResponse::where('survey_id', $survey->id)->exists()) return;

        $survey->questions()->delete(); // cascade apaga opções
        foreach ($questions as $i => $q) {
            $type = in_array(($q['type'] ?? 'text'), ['text', 'person', 'choice'], true) ? $q['type'] : 'text';
            $question = $survey->questions()->create([
                'label'    => trim((string) $q['label']),
                'type'     => $type,
                'required' => (bool) ($q['required'] ?? true),
                'multiple' => $type === 'choice' ? (bool) ($q['multiple'] ?? false) : false,
                'order'    => $i,
            ]);
            if ($type === 'choice') {
                $opts = array_values(array_filter(array_map('trim', (array) ($q['options'] ?? [])), fn ($s) => $s !== ''));
                foreach ($opts as $j => $label) {
                    $question->options()->create(['label' => $label, 'order' => $j]);
                }
            }
        }
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        $isPoll = $request->input('type') === 'poll';
        $isSurvey = $request->input('type') === 'survey';
        $noMsgRequired = $isPoll || $isSurvey; // enquete/pesquisa: mensagem opcional (o form é o conteúdo)
        return $request->validate([
            'title'        => ($creating ? 'required' : 'sometimes') . '|string|max:200',
            // Em enquete/pesquisa a mensagem é opcional (a pergunta/formulário é o conteúdo principal).
            'message'      => ($creating && !$noMsgRequired ? 'required' : 'nullable') . '|string',
            'type'         => ($creating ? 'required' : 'sometimes') . '|in:' . implode(',', AppNotification::TYPES),
            'priority'     => ($creating ? 'required' : 'sometimes') . '|in:' . implode(',', AppNotification::PRIORITIES),
            'target_roles'          => 'nullable|array',
            'target_users'          => 'nullable|array',
            'target_contract_types' => 'nullable|array',
            'target_bonds'          => 'nullable|array',
            'target_bonds.*'        => 'in:fixo,freelance',
            'excluded_user_ids'     => 'nullable|array',
            'excluded_user_ids.*'   => 'integer',
            'target_customer_id'    => 'nullable|exists:customers,id',
            'target_customer_ids'   => 'nullable|array',
            'target_customer_ids.*' => 'integer|exists:customers,id',
            'send_email'            => 'nullable|boolean',
            'visible'               => 'nullable|boolean',
            'recurrence'            => 'nullable|in:' . implode(',', AppNotification::RECURRENCES),
            'recurrence_value'      => 'nullable|integer|min:1|max:744',
            'recurrence_weekdays'   => 'nullable|array',
            'recurrence_weekdays.*' => 'integer|min:0|max:6',
            'is_template'           => 'nullable|boolean',
            'template_name'         => 'nullable|string|max:120',
            'requires_ack' => 'nullable|boolean',
            'cta_label'    => 'nullable|string|max:60',
            'cta_url'      => 'nullable|string|max:500',
            // Botões de decisão personalizados (nomes definidos pelo admin) — exige resposta.
            'actions'      => 'nullable|array|max:6',
            'actions.*'    => 'string|max:80',
            // Confirmar presença: permite ao respondente informar acompanhantes/familiares.
            'allow_guests' => 'nullable|boolean',
            // Usuários autorizados a indicar acompanhante "Outro" (além da família).
            'guest_other_user_ids'   => 'nullable|array',
            'guest_other_user_ids.*' => 'integer',
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
            // Pesquisa (quando type=survey): formulário com várias perguntas.
            'survey'                     => ($creating && $isSurvey ? 'required' : 'nullable') . '|array',
            'survey.anonymous'           => 'nullable|boolean',
            'survey.expires_at'          => 'nullable|date',
            'survey.questions'           => ($creating && $isSurvey ? 'required' : 'nullable') . '|array|min:1',
            'survey.questions.*.label'   => 'required_with:survey.questions|string|max:500',
            'survey.questions.*.type'    => 'nullable|in:text,person,choice',
            'survey.questions.*.required' => 'nullable|boolean',
            'survey.questions.*.multiple' => 'nullable|boolean',
            'survey.questions.*.options'   => 'nullable|array',
            'survey.questions.*.options.*' => 'string|max:200',
        ]);
    }

    /** Todos os e-mails dos destinatários (perfis + usuários + contratação + cliente). */
    private function resolveRecipientEmails(AppNotification $n): array
    {
        $customerIds = array_filter(array_merge(
            (array) ($n->target_customer_ids ?? []),
            $n->target_customer_id ? [$n->target_customer_id] : []
        ));
        $hasTarget = $n->target_roles || $n->target_users || $n->target_contract_types || $n->target_bonds || $customerIds;
        if (!$hasTarget) return [];
        return \App\Models\User::query()->whereNotNull('email')
            ->where(function ($w) use ($n, $customerIds) {
                if ($n->target_roles)          $w->orWhereIn('type', $n->target_roles);
                if ($n->target_users)          $w->orWhereIn('id', $n->target_users);
                if ($n->target_contract_types) $w->orWhereIn('contract_type', $n->target_contract_types);
                if ($n->target_bonds)          $w->orWhereIn('work_bond', $n->target_bonds);
                if ($customerIds)              $w->orWhereIn('customer_id', $customerIds);
            })
            ->when($n->excluded_user_ids, fn ($q) => $q->whereNotIn('id', $n->excluded_user_ids))
            ->pluck('email')->map(fn ($e) => mb_strtolower(trim($e)))->filter()->unique()->values()->all();
    }

    /**
     * Envia a notificação por e-mail com layout institucional. Recorrência reusa isto (reenvia a cada disparo).
     * $extraBcc = destinatários extras vindos da Central de Workflows (cópias configuradas pelo admin).
     */
    public function emailNotification(AppNotification $n, array $extraBcc = []): int
    {
        if ($n->is_template) return 0;  // modelo não dispara nada
        if (!$n->send_email || !\App\Services\GraphMailSender::enabled()) return 0;
        // Notificações saem do NOREPLY (config do mailbox Graph → MAIL_FROM_ADDRESS=noreply); HelpDeskEmailAccount só fallback.
        $from = config('services.graph.mailbox', env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS')))
            ?: \App\Models\HelpDeskEmailAccount::where('provider', 'microsoft365')->where('enabled', true)->orderBy('id')->value('email');
        if (!$from) return 0;

        $inlineBase = \App\Services\HelpDeskMailComposer::inlineAssetsSimple();

        // PESQUISA: cada destinatário recebe o e-mail com um botão "Responder pesquisa" (link assinado por usuário).
        // Quem já respondeu (ack_at) não recebe de novo nas recorrências.
        if ($n->type === 'survey' && $n->survey) {
            $survey = $n->survey;
            $decidedIds = NotificationRead::where('notification_id', $n->id)->whereNotNull('ack_at')->pluck('user_id')->all();
            $users = $this->resolveRecipientUsers($n)
                ->filter(fn ($u) => filled($u->email) && !in_array($u->id, $decidedIds, true));
            $sent = 0;
            foreach ($users as $u) {
                $link = \App\Http\Controllers\NotificationSurveyController::emailLink($survey, $u);
                $btn = '<div style="margin:18px 0 4px"><a href="' . e($link) . '" target="_blank" '
                    . 'style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:700;color:#ffffff;background:#06b6d4;border-radius:8px;text-decoration:none">Responder pesquisa</a></div>'
                    . '<p style="margin:8px 0 0;font-size:12px;color:#6b7280">Clique para abrir o formulário' . ($survey->anonymous ? ' (anônimo)' : '') . '. Ou acesse a Central de Notificações no Minutor.</p>';
                $body = (trim((string) $n->message) !== '' ? '<p style="margin:0 0 10px">' . $n->message . '</p>' : '') . $btn;
                $html = \App\Services\HelpDeskMailComposer::composeSimple($n->title, $body);
                [$html, $imgAtts] = \App\Services\HelpDeskMailComposer::inlineImages($html);
                [$ok, $err] = \App\Services\GraphMailSender::sendAs($from, [$u->email], [], $n->title, $html, [], array_merge($inlineBase, $imgAtts), false, []);
                if ($ok) $sent++;
                else \Illuminate\Support\Facades\Log::warning('Falha ao enviar e-mail de pesquisa (Graph)', ['notification_id' => $n->id, 'to' => $u->email, 'erro' => $err]);
            }
            return $sent;
        }

        // COM botões de decisão: cada destinatário recebe o e-mail com SEUS botões de 1 clique (link assinado).
        if (!empty($n->actions)) {
            // Quem JÁ decidiu (respondeu um botão / confirmou → ack_at gravado) NÃO recebe o e-mail
            // de novo nas recorrências/reenvios. Só continua recebendo quem NÃO respondeu ou clicou
            // "Decidir depois" (que não grava ack_at). Espelha a lógica do pop-up em fire().
            $decidedIds = NotificationRead::where('notification_id', $n->id)
                ->whereNotNull('ack_at')
                ->pluck('user_id')->all();
            $users = $this->resolveRecipientUsers($n)
                ->filter(fn ($u) => filled($u->email) && !in_array($u->id, $decidedIds, true));
            $sent = 0;
            foreach ($users as $u) {
                $body = $this->emailBody($n) . $this->actionButtonsHtml($n, $u);
                $html = \App\Services\HelpDeskMailComposer::composeSimple($n->title, $body);
                [$html, $imgAtts] = \App\Services\HelpDeskMailComposer::inlineImages($html);
                [$ok, $err] = \App\Services\GraphMailSender::sendAs($from, [$u->email], [], $n->title, $html, [], array_merge($inlineBase, $imgAtts), false, []);
                if ($ok) {
                    $sent++;
                } else {
                    // Antes as falhas do Graph eram engolidas em silêncio ("enviou e não chegou").
                    \Illuminate\Support\Facades\Log::warning('Falha ao enviar e-mail de publicação (Graph)', [
                        'notification_id' => $n->id, 'to' => $u->email, 'from' => $from, 'erro' => $err,
                    ]);
                }
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
        [$ok, $err] = \App\Services\GraphMailSender::sendAs($from, [$from], [], $n->title, $html, [], array_merge($inlineBase, $imgAtts), false, $emails);
        if (!$ok) {
            \Illuminate\Support\Facades\Log::warning('Falha ao enviar e-mail de publicação (Graph, BCC)', [
                'notification_id' => $n->id, 'bcc_count' => count($emails), 'from' => $from, 'erro' => $err,
            ]);
            return 0;
        }
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
            $r->response_source = 'email';                      // respondeu pelo link do e-mail (1 clique)
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

        // allow_guests + resposta AFIRMATIVA (1ª ação) recém-registrada → em vez do agradecimento
        // simples, pergunta os acompanhantes/familiares (mesma pergunta do pop-up da Central).
        // A presença já ficou confirmada acima; o formulário só ACRESCENTA os acompanhantes.
        $actionsList = array_values(array_filter(
            array_map(fn ($a) => trim((string) $a), (array) ($notification->actions ?? [])),
            fn ($a) => $a !== ''
        ));
        if ($ok && $u && $notification->allow_guests && isset($actionsList[0]) && $action === $actionsList[0]) {
            return response($this->guestFormPage($notification, $u), 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

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

    /** Página simples de resultado (dark) reutilizada pelos fluxos de e-mail. */
    private function simpleResultPage(AppNotification $n, bool $ok, string $msg): string
    {
        $heading = e($n->title);
        $color   = $ok ? '#06b6d4' : '#b91c1c';
        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $heading . '</title></head>'
            . '<body style="margin:0;font-family:Segoe UI,Arial,sans-serif;background:#0b0f14;color:#e5e7eb;display:flex;min-height:100vh;align-items:center;justify-content:center">'
            . '<div style="max-width:440px;padding:32px;text-align:center;background:#111827;border:1px solid #1f2937;border-radius:16px">'
            . '<div style="font-size:34px;margin-bottom:8px;color:' . $color . '">' . ($ok ? '&#10003;' : '&#9888;') . '</div>'
            . '<h1 style="font-size:18px;margin:0 0 6px;color:#fff">' . $heading . '</h1>'
            . '<p style="font-size:14px;color:#9ca3af;margin:0">' . $msg . '</p>'
            . '<p style="font-size:12px;color:#6b7280;margin:18px 0 0">Você já pode fechar esta janela.</p>'
            . '</div></body></html>';
    }

    /**
     * Página de ACOMPANHANTES exibida após o convidado confirmar presença pelo e-mail (1 clique).
     * A presença já foi registrada; aqui ele adiciona (opcionalmente) os familiares, que vão
     * via POST assinado para respondEmailGuests. Mesma regra do pop-up da Central.
     */
    private function guestFormPage(AppNotification $n, \App\Models\User $u): string
    {
        $expires = $n->expires_at && $n->expires_at->isFuture() ? $n->expires_at : now()->addDay();
        $postUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'notifications.respond-email-guests', $expires,
            ['notification' => $n->id, 'user' => $u->id]
        );
        $title  = e($n->title);
        $action = e($postUrl);
        // Pode indicar "Outro" (além da família)?
        $canOther = in_array((int) $u->id, array_map('intval', (array) ($n->guest_other_user_ids ?? [])), true);
        $otherOpt   = $canOther ? '<option value="Outro">Outro</option>' : '';
        $canOtherJs = $canOther ? 'true' : 'false';

        return <<<HTML
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title></head>
<body style="margin:0;font-family:Segoe UI,Arial,sans-serif;background:#0b0f14;color:#e5e7eb;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px">
<div style="max-width:520px;width:100%;padding:28px;background:#111827;border:1px solid #1f2937;border-radius:16px">
  <div style="font-size:30px;margin-bottom:6px;color:#06b6d4;text-align:center">&#10003;</div>
  <h1 style="font-size:18px;margin:0 0 4px;color:#fff;text-align:center">Presença confirmada!</h1>
  <p style="font-size:14px;color:#9ca3af;margin:0 0 18px;text-align:center">Vai levar acompanhantes/familiares? <span style="color:#6b7280">(opcional)</span></p>
  <form method="POST" action="{$action}">
    <div id="rows"></div>
    <button type="button" onclick="addRow()" style="margin:4px 0 16px;padding:8px 14px;background:#1f2937;color:#e5e7eb;border:1px solid #374151;border-radius:8px;font-size:13px;cursor:pointer">+ Adicionar acompanhante</button>
    <button type="submit" style="display:block;width:100%;padding:12px;background:#06b6d4;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer">Salvar acompanhantes</button>
    <button type="button" formnovalidate onclick="goAlone(this)" style="display:block;width:100%;margin-top:8px;padding:11px;background:transparent;color:#9ca3af;border:1px solid #374151;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer">Vou sozinho(a) &mdash; sem acompanhantes</button>
    <div style="margin:18px 0 0;padding:16px 18px;background:rgba(6,182,212,.12);border:1px solid #06b6d4;border-radius:12px;text-align:center">
      <p style="margin:0;font-size:17px;font-weight:700;color:#e5e7eb;line-height:1.45">Se não levará ninguém, pode fechar esta janela &mdash; sua presença já está confirmada.</p>
    </div>
  </form>
</div>
<script>
var i = 0;
var CAN_OTHER = {$canOtherJs};
function addRow() {
  var wrap = document.getElementById('rows');
  var idx = i++;
  var row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;align-items:center';
  row.innerHTML =
    '<input name="guests['+idx+'][nome]" placeholder="Nome" required style="flex:2 1 150px;min-width:0;padding:9px;background:#0b0f14;border:1px solid #374151;border-radius:8px;color:#e5e7eb;font-size:13px">' +
    '<select name="guests['+idx+'][parentesco]" required onchange="toggleRow(this)" style="flex:1 1 120px;padding:9px;background:#0b0f14;border:1px solid #374151;border-radius:8px;color:#e5e7eb;font-size:13px">' +
      '<option value="">Parentesco</option><option value="Cônjuge">Cônjuge</option><option value="Filho(a)">Filho(a)</option>{$otherOpt}' +
    '</select>' +
    '<label class="crianca" style="display:none;align-items:center;gap:4px;font-size:12px;color:#9ca3af;cursor:pointer"><input type="checkbox" onchange="toggleCrianca(this)"> é criança?</label>' +
    '<input name="guests['+idx+'][idade]" type="number" min="0" max="120" placeholder="Idade" style="display:none;flex:0 1 90px;padding:9px;background:#0b0f14;border:1px solid #374151;border-radius:8px;color:#e5e7eb;font-size:13px">' +
    '<button type="button" onclick="this.parentNode.remove()" title="Remover" style="flex:0 0 auto;padding:0 12px;height:38px;background:#1f2937;border:1px solid #374151;border-radius:8px;color:#9ca3af;cursor:pointer">&times;</button>';
  wrap.appendChild(row);
}
// Mostra/oculta "é criança?" e Idade conforme o parentesco.
function toggleRow(sel) {
  var row = sel.parentNode;
  var crianca = row.querySelector('.crianca');
  var cb = crianca.querySelector('input[type=checkbox]');
  var idade = row.querySelector('input[type=number]');
  var isFilho = /filho/i.test(sel.value);
  var isOutro = /outro/i.test(sel.value);
  crianca.style.display = isOutro ? 'inline-flex' : 'none';
  if (!isOutro) cb.checked = false;
  // Filho(a): idade obrigatória sempre. Outro: idade só quando "é criança".
  var showIdade = isFilho || (isOutro && cb.checked);
  idade.style.display = showIdade ? 'block' : 'none';
  idade.required = showIdade;
  if (!showIdade) idade.value = '';
}
function toggleCrianca(cb) {
  var idade = cb.parentNode.parentNode.querySelector('input[type=number]');
  idade.style.display = cb.checked ? 'block' : 'none';
  idade.required = cb.checked;
  if (!cb.checked) idade.value = '';
}
function goAlone(btn) {
  document.getElementById('rows').innerHTML = '';
  btn.form.submit();
}
addRow();
</script>
</body></html>
HTML;
    }

    /**
     * Grava os acompanhantes informados na página pós-confirmação do e-mail (POST assinado).
     * Só grava se o usuário JÁ confirmou presença (resposta afirmativa). Regrava a lista (idempotente).
     */
    public function respondEmailGuests(Request $request, AppNotification $notification)
    {
        $u = \App\Models\User::find((int) $request->query('user'));
        $actions = array_values(array_filter(
            array_map(fn ($a) => trim((string) $a), (array) ($notification->actions ?? [])),
            fn ($a) => $a !== ''
        ));
        $read = $u ? NotificationRead::where('notification_id', $notification->id)->where('user_id', $u->id)->first() : null;
        $affirmative = $u && $notification->allow_guests && $read && isset($actions[0]) && $read->response_action === $actions[0];

        if (!$affirmative) {
            return response($this->simpleResultPage(
                $notification, false,
                'Não foi possível registrar os acompanhantes. Confirme a presença primeiro ou acesse a Central de Notificações no Minutor.'
            ), 200)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        // Regrava a lista do respondente (idempotente) — mesma regra do respond() da Central.
        \App\Models\NotificationGuest::where('notification_id', $notification->id)->where('user_id', $u->id)->delete();
        $guests = $this->parseGuests($request->input('guests', []), $notification, $u->id);
        if ($guests) \App\Models\NotificationGuest::insert($guests);

        $cnt = count($guests);
        $msg = $cnt > 0
            ? 'Presença confirmada com <b>' . $cnt . '</b> acompanhante(s). Obrigado!'
            : 'Presença confirmada, sem acompanhantes. Obrigado!';
        return response($this->simpleResultPage($notification, true, $msg), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Corpo do e-mail: mensagem da notificação ou, em enquete, pergunta + opções + chamada p/ votar. */
    private function emailBody(AppNotification $n, ?array $pollPayload = null): string
    {
        if ($n->type !== 'poll') {
            $msg = (string) $n->message;
            // Botão de AÇÃO (cta_label + cta_url): aparece no e-mail (preview, reenvio e envio).
            if (!empty($n->cta_label) && !empty($n->cta_url)) {
                $url = trim((string) $n->cta_url);
                if (str_starts_with($url, '/')) {
                    $url = rtrim((string) config('app.frontend_url', config('app.url', '')), '/') . $url;  // rota interna → app
                } elseif (!preg_match('#^https?://#i', $url)) {
                    $url = 'https://' . $url;  // domínio sem esquema (ex.: minutor.com.br)
                }
                $msg .= '<div style="margin:18px 0 4px"><a href="' . e($url) . '" target="_blank" '
                    . 'style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:700;color:#ffffff;background:#7c3aed;border-radius:8px;text-decoration:none">'
                    . e($n->cta_label) . '</a></div>';
            }
            return $msg;
        }

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
        abort_unless($this->canManage($request->user()), 403);
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
        abort_unless($this->canManage($request->user()), 403);
        return response()->json(['data' => [
            'contract_types' => [['id' => 'clt', 'name' => 'CLT'], ['id' => 'cooperado', 'name' => 'Cooperado'], ['id' => 'pj', 'name' => 'PJ']],
            'customers'      => \App\Models\Customer::orderBy('name')->limit(1000)->get(['id', 'name']),
        ]]);
    }

    /** Busca de usuários p/ destinatários específicos. */
    public function searchUsers(Request $request): JsonResponse
    {
        abort_unless($this->canManage($request->user()), 403);
        $q = trim((string) $request->query('search', ''));
        $type = trim((string) $request->query('type', ''));               // filtra por perfil (aba do Configurador)
        $coord = trim((string) $request->query('coordinator_type', ''));   // coordenador projetos/sustentação
        $rows = \App\Models\User::query()->whereNotNull('email')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")))
            // O Configurador manda a "profile key" EFETIVA (parceiro_gestor/simples,
            // consultor_horista/…, coordenador_projetos/…), que NÃO é igual a users.type
            // (parceiro_admin, consultor, coordenador). Traduz p/ o filtro real; senão a
            // busca por parceiro/consultor/coordenador-subtipo não trazia ninguém.
            ->when($type !== '', function ($w) use ($type) {
                switch ($type) {
                    case 'parceiro_gestor':          $w->where('type', 'parceiro_admin')->where('is_executive', true); break;
                    case 'parceiro_simples':         $w->where('type', 'parceiro_admin')->where(fn ($x) => $x->where('is_executive', false)->orWhereNull('is_executive')); break;
                    case 'coordenador_projetos':     $w->where('type', 'coordenador')->where('coordinator_type', 'projetos'); break;
                    case 'coordenador_sustentacao':  $w->where('type', 'coordenador')->where('coordinator_type', 'sustentacao'); break;
                    case 'consultor_horista':        $w->where('type', 'consultor')->where('consultant_type', 'horista'); break;
                    case 'consultor_banco_de_horas': $w->where('type', 'consultor')->where('consultant_type', 'banco_de_horas'); break;
                    case 'consultor_fixo':           $w->where('type', 'consultor')->where('consultant_type', 'fixo'); break;
                    default:                         $w->where('type', $type); break; // admin, administrativo, cliente, consultor, coordenador
                }
            })
            ->when($coord !== '', fn ($w) => $w->where('coordinator_type', $coord))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'email', 'type', 'coordinator_type']);
        return response()->json(['data' => $rows]);
    }
}
