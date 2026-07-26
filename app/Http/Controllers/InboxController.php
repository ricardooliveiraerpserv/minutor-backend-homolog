<?php

namespace App\Http\Controllers;

use App\Enums\NotificationStatus;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Inbox\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function __construct(protected InboxService $inbox)
    {
    }

    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->inbox->ensureBotConversation($user);

        $items = $this->inbox->listConversations($user);
        return response()->json(['data' => $items]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::query()
            ->forUser($user->id)
            ->with(['customer:id,name'])
            ->findOrFail($id);

        return response()->json(['data' => (new ConversationResource($conv))->toArray($request)]);
    }

    public function messages(Request $request, int $id)
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        $perPage = (int) $request->query('per_page', 50);

        // Filtro opcional por status
        $statusFilter = $request->query('status'); // unread|read|resolved|archived|snoozed ou CSV
        $q = $conv->messages()->with('sender:id,name')->orderByDesc('created_at');

        if ($statusFilter) {
            $statuses = is_array($statusFilter) ? $statusFilter : explode(',', $statusFilter);
            $q->whereIn('status', $statuses);
        }

        return MessageResource::collection($q->paginate(min($perPage, 100)));
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        // Aceita JSON {body, metadata, reply_to_id} OU multipart com files[].
        // body é opcional quando há arquivos.
        $hasFiles = $request->hasFile('files');
        $rules = [
            'body'        => $hasFiles ? 'nullable|string|max:8000' : 'required|string|max:8000',
            'metadata'    => 'nullable|array',
            'reply_to_id' => 'nullable|integer|exists:messages,id',
            'files'       => 'nullable|array|max:10',
            'files.*'     => 'file|max:20480|mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip,rar,7z', // 20 MB; whitelist anti-RCE/XSS
        ];
        $data = $request->validate($rules);

        // Valida que a mensagem alvo de reply pertence à mesma conversa
        $replyToId = null;
        if (! empty($data['reply_to_id'])) {
            $target = Message::find($data['reply_to_id']);
            if ($target && $target->conversation_id === $conv->id) {
                $replyToId = $target->id;
            }
        }

        $body = $data['body'] ?? '';
        $msg = $this->inbox->sendUserMessage($conv, $user, $body, $data['metadata'] ?? null, $replyToId);

        // Salva arquivos (se houver) e linka como MessageAttachment
        if ($hasFiles) {
            foreach ($request->file('files') as $file) {
                $stored = $file->store("chat/{$conv->id}", 'public');
                $msg->attachments()->create([
                    'filename'    => $file->getClientOriginalName(),
                    'stored_path' => $stored,
                    'mime'        => $file->getMimeType() ?: 'application/octet-stream',
                    'size'        => $file->getSize(),
                ]);
            }
        }

        $msg->load(['sender:id,name', 'attachments']);

        return (new MessageResource($msg))
            ->response()
            ->setStatusCode(201);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        $this->inbox->markRead($conv, $user);

        // Marca todas as messages do conv como `read` (em vez de só atualizar last_read_at)
        $conv->messages()
            ->where('status', NotificationStatus::Unread->value)
            ->update(['status' => NotificationStatus::Read->value]);

        return response()->json(['marked_read' => true]);
    }

    /**
     * PATCH /api/v1/inbox/messages/{id}/status
     * body: { "status": "resolved|archived|snoozed|read|unread", "snoozed_until"?: "iso8601" }
     */
    public function updateMessageStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'status'        => 'required|in:unread,read,resolved,archived,snoozed',
            'snoozed_until' => 'nullable|date|after:now',
        ]);

        $message = Message::with('conversation.participants')->findOrFail($id);

        // Garante que o user participa da conversation da message
        $isParticipant = $message->conversation->participants->contains('user_id', $user->id);
        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $status = NotificationStatus::from($data['status']);
        $snoozeUntil = ! empty($data['snoozed_until']) ? new \DateTime($data['snoozed_until']) : null;

        $message = $this->inbox->updateMessageStatus($message, $status, $user, $snoozeUntil);

        return response()->json(['data' => [
            'id'            => $message->id,
            'status'        => $message->status->value,
            'snoozed_until' => $message->snoozed_until?->toIso8601String(),
            'resolved_at'   => $message->resolved_at?->toIso8601String(),
            'resolved_by'   => $message->resolved_by,
        ]]);
    }

    /**
     * PATCH /api/v1/inbox/messages/{id}
     * Edita corpo de uma mensagem própria (janela de 5 min após envio).
     */
    public function updateMessage(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['body' => 'required|string|max:8000']);

        $message = Message::with('conversation.participants')->findOrFail($id);

        if ($message->sender_user_id !== $user->id) {
            return response()->json(['message' => 'Você só pode editar suas próprias mensagens.'], 403);
        }
        if ($message->deleted_at !== null) {
            return response()->json(['message' => 'Mensagem já excluída.'], 422);
        }
        if ($message->created_at && $message->created_at->diffInMinutes(now()) > 5) {
            return response()->json(['message' => 'Janela de edição expirou (5 minutos).'], 422);
        }

        $message->update([
            'body'      => $data['body'],
            'edited_at' => now(),
        ]);

        $message->load('sender:id,name');
        return (new MessageResource($message))->response();
    }

    /**
     * DELETE /api/v1/inbox/messages/{id}
     * Marca mensagem como excluída (soft delete via campo deleted_at).
     * Apenas o autor pode excluir; conteúdo é apagado, registro mantido.
     */
    public function destroyMessage(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $message = Message::with('conversation.participants')->findOrFail($id);

        if ($message->sender_user_id !== $user->id) {
            return response()->json(['message' => 'Você só pode excluir suas próprias mensagens.'], 403);
        }
        if ($message->deleted_at !== null) {
            return response()->json(['data' => ['id' => $message->id, 'deleted' => true]]);
        }

        $message->update([
            'body'       => '[mensagem excluída]',
            'deleted_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $message->id, 'deleted' => true]]);
    }

    /**
     * POST /api/v1/inbox/messages/{id}/favorite
     * Toggle favorito pessoal — só visível pelo próprio user.
     */
    public function toggleFavorite(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $message = Message::with('conversation.participants')->findOrFail($id);
        $isParticipant = $message->conversation->participants->contains('user_id', $user->id);
        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $existing = \App\Models\MessageFavorite::query()
            ->where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['action' => 'removed', 'is_favorite' => false]);
        }

        \App\Models\MessageFavorite::create([
            'message_id' => $message->id,
            'user_id'    => $user->id,
        ]);
        return response()->json(['action' => 'added', 'is_favorite' => true]);
    }

    /**
     * GET /api/v1/inbox/favorites
     * Lista mensagens favoritas do usuário atual.
     */
    public function listFavorites(Request $request): JsonResponse
    {
        $user = $request->user();
        $messageIds = \App\Models\MessageFavorite::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('message_id');

        $items = Message::query()
            ->whereIn('id', $messageIds)
            ->with(['sender:id,name', 'attachments', 'conversation:id,type,title'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $items->map(function ($m) use ($request) {
                $r = (new MessageResource($m))->toArray($request);
                $r['conversation'] = $m->conversation ? [
                    'id'    => $m->conversation->id,
                    'type'  => $m->conversation->type?->value,
                    'title' => $m->conversation->title,
                ] : null;
                return $r;
            })->all(),
        ]);
    }

    /**
     * GET /api/v1/inbox/conversations/{id}/read-status
     * Retorna lista de participantes (exceto o próprio) com last_read_at — frontend
     * usa pra mostrar "lido por X em HH:mm" em cada mensagem.
     */
    public function readStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        $items = \App\Models\ConversationParticipant::query()
            ->where('conversation_id', $conv->id)
            ->where('user_id', '!=', $user->id)
            ->with('user:id,name')
            ->get()
            ->map(fn ($p) => [
                'user_id'      => $p->user_id,
                'name'         => $p->user?->name,
                'last_read_at' => $p->last_read_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['data' => $items]);
    }

    /**
     * POST /api/v1/inbox/conversations/{id}/mute
     * body opcional: { hours: int|null }  → null/0 = desmuta, número = muta por X horas
     */
    public function muteConversation(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['hours' => 'nullable|integer|min:0|max:8760']); // até 1 ano

        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);
        $participant = \App\Models\ConversationParticipant::query()
            ->where('conversation_id', $conv->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $hours = (int) ($data['hours'] ?? 0);
        if ($hours <= 0) {
            $participant->update(['muted_until' => null, 'muted' => false]);
            return response()->json(['muted_until' => null]);
        }

        $until = now()->addHours($hours);
        $participant->update(['muted_until' => $until, 'muted' => true]);
        return response()->json(['muted_until' => $until->toIso8601String()]);
    }

    /**
     * POST /api/v1/inbox/messages/{id}/pin
     * Toggle pin: fixa/desafixa mensagem na conversa.
     */
    public function togglePin(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $message = Message::with('conversation.participants')->findOrFail($id);
        $isParticipant = $message->conversation->participants->contains('user_id', $user->id);
        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($message->pinned_at) {
            $message->update(['pinned_at' => null, 'pinned_by' => null]);
            return response()->json(['action' => 'unpinned', 'message_id' => $message->id]);
        }

        $message->update(['pinned_at' => now(), 'pinned_by' => $user->id]);
        return response()->json(['action' => 'pinned', 'message_id' => $message->id]);
    }

    /**
     * GET /api/v1/inbox/conversations/{id}/pinned
     * Lista mensagens fixadas da conversa (ordem mais recente primeiro).
     */
    /**
     * GET /api/v1/inbox/search?q=...&limit=50
     * Busca mensagens em TODAS as conversas onde o usuário é participante.
     * Retorna até 50 hits ordenados do mais recente. Cada hit inclui snippet + conversation.
     */
    public function searchMessages(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $limit = max(1, min((int) $request->query('limit', 50), 100));

        if (mb_strlen($q) < 2) {
            return response()->json(['data' => [], 'query' => $q, 'count' => 0]);
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        // IDs das conversas do user
        $convIds = \App\Models\ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->pluck('conversation_id');

        $items = Message::query()
            ->whereIn('conversation_id', $convIds)
            ->whereNull('deleted_at')
            ->where('body', 'ilike', $like)
            ->with(['sender:id,name', 'conversation:id,type,title'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'query' => $q,
            'count' => $items->count(),
            'data'  => $items->map(function (Message $m) use ($q) {
                $body = $m->body ?? '';
                // snippet com contexto ±60 chars ao redor do match
                $pos = mb_stripos($body, $q);
                $snippet = $body;
                if ($pos !== false && mb_strlen($body) > 160) {
                    $startPos = max(0, $pos - 60);
                    $snippet = ($startPos > 0 ? '…' : '') . mb_substr($body, $startPos, 160) . (mb_strlen($body) > $startPos + 160 ? '…' : '');
                }
                return [
                    'id'          => $m->id,
                    'snippet'     => $snippet,
                    'sender'      => $m->sender ? ['id' => $m->sender->id, 'name' => $m->sender->name] : null,
                    'created_at'  => $m->created_at?->toIso8601String(),
                    'conversation' => $m->conversation ? [
                        'id'    => $m->conversation->id,
                        'type'  => $m->conversation->type?->value,
                        'title' => $m->conversation->title,
                    ] : null,
                ];
            })->all(),
        ]);
    }

    /**
     * POST /api/v1/inbox/conversations/{id}/typing
     * Registra que o usuário está digitando (TTL ~5s).
     */
    public function setTyping(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        \App\Models\ConversationParticipant::query()
            ->where('conversation_id', $conv->id)
            ->where('user_id', $user->id)
            ->update(['last_typed_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/v1/inbox/conversations/{id}/typing
     * Retorna participantes que digitaram nos últimos 5s (exceto eu).
     */
    public function listTyping(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        $threshold = now()->subSeconds(5);
        $items = \App\Models\ConversationParticipant::query()
            ->where('conversation_id', $conv->id)
            ->where('user_id', '!=', $user->id)
            ->where('last_typed_at', '>=', $threshold)
            ->with('user:id,name')
            ->get()
            ->map(fn ($p) => [
                'user_id' => $p->user_id,
                'name'    => $p->user?->name,
            ])
            ->all();

        return response()->json(['data' => $items]);
    }

    /**
     * GET /api/v1/inbox/conversations/{id}/export?format=txt|json
     * Baixa transcript da conversa. Apenas participantes têm acesso.
     */
    public function exportConversation(Request $request, int $id)
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);
        $format = $request->query('format', 'txt');

        $messages = $conv->messages()
            ->with(['sender:id,name', 'attachments'])
            ->orderBy('created_at')
            ->get();

        $title = $conv->title ?: ('conversa_' . $conv->id);
        $slug  = preg_replace('/[^a-zA-Z0-9_-]+/', '_', mb_strtolower($title));
        $stamp = now()->format('Ymd_His');

        if ($format === 'json') {
            $payload = [
                'conversation' => [
                    'id'    => $conv->id,
                    'type'  => $conv->type?->value,
                    'title' => $conv->title,
                ],
                'exported_at'  => now()->toIso8601String(),
                'exported_by'  => ['id' => $user->id, 'name' => $user->name],
                'messages' => $messages->map(fn ($m) => [
                    'id'           => $m->id,
                    'sender'       => $m->sender ? ['id' => $m->sender->id, 'name' => $m->sender->name] : null,
                    'type'         => $m->type?->value,
                    'body'         => $m->body,
                    'created_at'   => $m->created_at?->toIso8601String(),
                    'edited_at'    => $m->edited_at?->toIso8601String(),
                    'deleted_at'   => $m->deleted_at?->toIso8601String(),
                    'attachments'  => $m->attachments->map(fn ($a) => [
                        'filename' => $a->filename,
                        'mime'     => $a->mime,
                        'size'     => $a->size,
                        'url'      => $a->url,
                    ])->all(),
                ])->all(),
            ];
            return response()->json($payload)
                ->header('Content-Disposition', "attachment; filename=\"{$slug}_{$stamp}.json\"");
        }

        // formato texto plano (default)
        $lines = [];
        $lines[] = '# ' . ($conv->title ?: 'Conversa #' . $conv->id);
        $lines[] = 'Exportado em ' . now()->format('d/m/Y H:i') . ' por ' . $user->name;
        $lines[] = str_repeat('-', 60);
        $lines[] = '';

        foreach ($messages as $m) {
            $ts = $m->created_at?->format('d/m/Y H:i') ?? '?';
            $who = $m->sender?->name ?? ($m->type?->value === 'bot' ? 'BOT Minutor' : 'Sistema');
            $body = $m->deleted_at ? '[mensagem excluída]' : $m->body;
            $suffix = $m->edited_at ? ' (editada)' : '';
            $lines[] = "[{$ts}] {$who}{$suffix}:";
            foreach (explode("\n", $body) as $bodyLine) {
                $lines[] = '  ' . $bodyLine;
            }
            foreach ($m->attachments as $a) {
                $lines[] = "  📎 anexo: {$a->filename} ({$a->mime}, " . number_format($a->size / 1024, 1) . " KB)";
            }
            $lines[] = '';
        }

        $content = implode("\n", $lines);

        return response($content, 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$slug}_{$stamp}.txt\"",
        ]);
    }

    public function pinnedMessages(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::query()->forUser($user->id)->findOrFail($id);

        $items = $conv->messages()
            ->whereNotNull('pinned_at')
            ->with(['sender:id,name', 'attachments'])
            ->orderByDesc('pinned_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $items->map(fn ($m) => (new MessageResource($m))->toArray($request))->all(),
        ]);
    }

    /**
     * POST /api/v1/inbox/messages/{id}/reactions
     * body: { emoji: "👍" }  → toggle (adiciona se não existir, remove se já existir)
     */
    public function toggleReaction(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['emoji' => 'required|string|max:16']);

        $message = Message::with('conversation.participants')->findOrFail($id);
        $isParticipant = $message->conversation->participants->contains('user_id', $user->id);
        if (! $isParticipant) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $existing = \App\Models\MessageReaction::query()
            ->where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $data['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();
            $action = 'removed';
        } else {
            \App\Models\MessageReaction::create([
                'message_id' => $message->id,
                'user_id'    => $user->id,
                'emoji'      => $data['emoji'],
            ]);
            $action = 'added';
        }

        // Retorna reactions agrupadas atualizadas
        $message->load(['reactions.user:id,name']);
        return response()->json([
            'action'    => $action,
            'reactions' => $this->groupReactions($message, $user->id),
        ]);
    }

    private function groupReactions(Message $message, int $currentUserId): array
    {
        $groups = [];
        foreach ($message->reactions as $r) {
            $emoji = $r->emoji;
            if (! isset($groups[$emoji])) {
                $groups[$emoji] = ['emoji' => $emoji, 'count' => 0, 'by_me' => false, 'users' => []];
            }
            $groups[$emoji]['count']++;
            if ($r->user_id === $currentUserId) $groups[$emoji]['by_me'] = true;
            if ($r->user) {
                $groups[$emoji]['users'][] = ['id' => $r->user->id, 'name' => $r->user->name];
            }
        }
        return array_values($groups);
    }

    /**
     * GET /api/v1/inbox/unread-summary
     * Retorna agora também breakdown por severity.
     */
    public function unreadSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $conversations = $this->inbox->listConversations($user);

        $total = array_sum(array_map(fn ($c) => $c['unread_count'], $conversations));

        $bySeverity = [
            'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0,
        ];
        foreach ($conversations as $c) {
            foreach ($bySeverity as $k => $_) {
                $bySeverity[$k] += $c['unread_by_severity'][$k] ?? 0;
            }
        }

        return response()->json([
            'total_unread'        => $total,
            'by_severity'         => $bySeverity,
            'conversations_count' => count($conversations),
        ]);
    }
}
