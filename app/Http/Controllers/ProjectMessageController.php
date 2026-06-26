<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\CardEnvolvido;
use App\Models\Project;
use App\Models\ProjectMessage;
use App\Models\ProjectMessageMention;
use App\Models\ProjectMessageRead;
use App\Models\User;
use App\Notifications\CardChatMessageNotification;
use App\Services\CardEnvolvidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectMessageController extends Controller
{
    use \App\Http\Controllers\Concerns\DispatchesChatMentions;

    public function index(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        // Cliente não tem acesso ao chat de projeto — fluxo dele encerra na requisição.
        if ($user->isCliente()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $messages = ProjectMessage::where('project_id', $project->id)
            ->with([
                'author:id,name',
                'attachments',
                'reads' => fn($q) => $q->where('user_id', $user->id),
            ])
            ->withExists(['mentions as is_mentioned' => fn($q) => $q->where('mentioned_user_id', $user->id)])
            ->latest()
            ->paginate(50);

        return response()->json($messages);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        // Cliente bloqueado — chat de projeto é interno-only.
        if ($user->isCliente()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $request->validate([
            'message'    => 'nullable|string|max:5000',
            'priority'   => 'nullable|in:normal,high',
            'files'      => 'nullable|array|max:10',
            'files.*'    => 'file|max:20480', // 20 MB por arquivo
        ]);

        $text = $request->input('message', '');
        if (!$text && !$request->hasFile('files')) {
            return response()->json(['message' => 'Mensagem ou anexo obrigatório.'], 422);
        }

        // Toda mensagem em chat de projeto é interna — opção "visível ao cliente" removida.
        $visibility = 'internal';

        $msg = ProjectMessage::create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'message'    => $text,
            'priority'   => $request->input('priority', 'normal'),
            'visibility' => $visibility,
        ]);

        // Parse mention tokens @[id:Name] + fallback plain-text via MentionParser.
        // Cliente NUNCA pode ser mencionado em chat de projeto (regra ADR cards —
        // chat de projeto é interno; cliente não tem acesso).
        $candidates = User::query()
            ->select('id', 'name')
            ->whereIn('type', ['admin', 'coordenador', 'consultor', 'parceiro_admin', 'administrativo'])
            ->get();
        $mentionedIds = \App\Services\MentionParser::extract($text, $candidates);

        // Menção "Todos" (token @[all:...]) → expande pra todos os participantes
        // internos do projeto (admins + coordenadores + executivo). Cliente NUNCA
        // participa do chat de projeto. Exclui o próprio autor.
        if (preg_match('/@\[all:/i', $text)) {
            $mentionedIds = array_values(array_unique(array_merge(
                $mentionedIds,
                array_diff($this->projectMentionableUserIds($project), [$user->id])
            )));
        }

        foreach ($mentionedIds as $mentionedId) {
            ProjectMessageMention::firstOrCreate([
                'message_id'        => $msg->id,
                'mentioned_user_id' => $mentionedId,
            ]);
        }

        // FASE 11.7 (PR 7b) — Upload de anexos 100% via camada Attachment.
        if ($request->hasFile('files')) {
            $service = app(\App\Attachments\AttachmentService::class);
            foreach ($request->file('files') as $file) {
                $path = $file->store('message-attachments', 'public');
                $service->registerExisting($user, [
                    'entity_type'   => 'PROJECT_MESSAGE',
                    'entity_id'     => $msg->id,
                    'category'      => 'attachment',
                    'storage_path'  => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
                ]);
            }
        }

        $msg->load(['author:id,name', 'attachments']);
        $msg->is_mentioned = false;

        // Fase card-envolvidos: notifica envolvidos internos (cliente NUNCA recebe
        // chat de projeto — regra do CardEnvolvidoService). Best-effort.
        try {
            $this->dispatchChatNotification($project, $msg, $user, $mentionedIds);
        } catch (\Throwable $e) {
            \Log::warning('chat notif proj falhou', ['project_id' => $project->id, 'err' => $e->getMessage()]);
        }

        return response()->json($msg, 201);
    }

    /**
     * Editar uma interação do Diário do Projeto.
     * Regras: só o autor, só a SUA última interação no projeto, e dentro da
     * janela de 3h (ProjectMessage::EDIT_WINDOW_HOURS). Re-sincroniza menções
     * (chips), mas não reenvia notificação — edição é correção, não novo aviso.
     */
    public function update(Request $request, Project $project, ProjectMessage $message): JsonResponse
    {
        $user = $request->user();

        if ($user->isCliente()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }
        if ((int) $message->project_id !== (int) $project->id) {
            return response()->json(['message' => 'Interação não encontrada'], 404);
        }
        if ((int) $message->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Você só pode editar suas próprias interações.'], 403);
        }

        // Tem de ser a última interação DESTE usuário no projeto.
        $lastOwnId = ProjectMessage::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->max('id');
        if ((int) $message->id !== (int) $lastOwnId) {
            return response()->json(['message' => 'Só é possível editar sua última interação.'], 422);
        }

        // Janela de 3h a partir do envio.
        if ($message->created_at->lt(now()->subHours(ProjectMessage::EDIT_WINDOW_HOURS))) {
            return response()->json(['message' => 'O prazo de ' . ProjectMessage::EDIT_WINDOW_HOURS . 'h para editar esta interação expirou.'], 422);
        }

        $request->validate([
            'message' => 'required|string|max:5000',
        ]);
        $text = $request->input('message');

        $message->update(['message' => $text, 'edited_at' => now()]);

        // Re-sincroniza menções (mesma regra do store; cliente nunca é mencionado).
        $candidates = User::query()
            ->select('id', 'name')
            ->whereIn('type', ['admin', 'coordenador', 'consultor', 'parceiro_admin', 'administrativo'])
            ->get();
        $mentionedIds = \App\Services\MentionParser::extract($text, $candidates);
        if (preg_match('/@\[all:/i', $text)) {
            $mentionedIds = array_values(array_unique(array_merge(
                $mentionedIds,
                array_diff($this->projectMentionableUserIds($project), [$user->id])
            )));
        }
        $message->mentions()->whereNotIn('mentioned_user_id', $mentionedIds ?: [0])->delete();
        foreach ($mentionedIds as $mentionedId) {
            ProjectMessageMention::firstOrCreate([
                'message_id'        => $message->id,
                'mentioned_user_id' => $mentionedId,
            ]);
        }

        $message->load(['author:id,name', 'attachments']);
        $message->is_mentioned = in_array($user->id, $mentionedIds, true);

        return response()->json($message);
    }

    private function dispatchChatNotification(Project $project, ProjectMessage $msg, User $author, array $mentionedIds = []): void
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $cardUrl = $base . '/contratos/pipeline?project=' . $project->id;
        // tab=chat (query) é mais confiável que hash #chat em client-side nav (Next 16)
        $openUrl = $cardUrl . '&tab=chat';
        $code = $project->code ?? ('PRJ-' . str_pad((string) $project->id, 6, '0', STR_PAD_LEFT));
        $title = $project->name ?? 'Projeto';
        // Texto COMPLETO no e-mail (antes truncava em 280 chars). Cap alto só como salvaguarda.
        $excerpt = Str::limit($msg->message ?? '', 5000);
        $customerName = $project->customer?->name ?? '';
        $role = match ($author->type) {
            'admin' => 'Admin', 'coordenador' => 'Coordenador', 'consultor' => 'Consultor',
            'cliente' => 'Cliente', 'parceiro_admin' => 'Parceiro', 'administrativo' => 'Administrativo',
            default => 'Equipe',
        };

        $mkNotif = fn () => (new CardChatMessageNotification(
            cardType:       CardEnvolvido::TYPE_PROJECT,
            cardCode:       $code,
            cardTitle:      $title,
            authorName:     $author->name,
            authorRole:     $role,
            messageExcerpt: $excerpt,
            openUrl:        $openUrl,
            cardUrl:        $cardUrl,
            recipientName:  'você',
            customerName:   $customerName,
        ));

        $resolver = app(\App\Workflows\WorkflowRecipientResolver::class);

        // 1) Mensagem no chat → envolvidos do card.
        $rcpt = $resolver->resolve('card.chat_message.project', [
            'card'      => ['type' => CardEnvolvido::TYPE_PROJECT, 'id' => $project->id],
            'project'   => $project,
            'actor'     => $author,
            'mentioned' => $mentionedIds,
        ]);
        $chatTo = $rcpt['to'] ?? [];
        if (!empty($chatTo)) {
            Notification::route('mail', $chatTo)->notify($mkNotif()->withCc($rcpt['cc'] ?? []));
        }

        // 2) Marcação (@) → pessoa marcada, sem duplicar quem já recebeu acima.
        $this->dispatchMentionNotification(CardEnvolvido::TYPE_PROJECT, $project->id, $author, $mentionedIds, [
            'code' => $code, 'title' => $title, 'role' => $role, 'excerpt' => $excerpt, 'openUrl' => $openUrl, 'cardUrl' => $cardUrl, 'customer' => $customerName,
        ], array_merge($chatTo, $rcpt['cc'] ?? []));
    }

    public function downloadAttachment(Request $request, ProjectMessage $message, Attachment $attachment): mixed
    {
        $user = $request->user();

        if ($user->isCliente()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $message->project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        // FASE 11.7 (PR 7b) — valida vínculo polimórfico.
        if ($attachment->entity_type !== 'PROJECT_MESSAGE' || (int) $attachment->entity_id !== (int) $message->id) {
            return response()->json(['message' => 'Anexo não encontrado'], 404);
        }

        return Storage::disk('public')->download($attachment->storage_path, $attachment->original_name);
    }

    public function markRead(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        if ($user->isCliente()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        if (!$user->isAdmin() && !$this->userCanAccessProject($user, $project)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $query = ProjectMessage::where('project_id', $project->id)
            ->where('user_id', '!=', $user->id)
            ->whereDoesntHave('reads', fn($q) => $q->where('user_id', $user->id));

        $unreadIds = $query->pluck('id');

        if ($unreadIds->isNotEmpty()) {
            $now  = now();
            $rows = $unreadIds->map(fn($id) => [
                'message_id' => $id,
                'user_id'    => $user->id,
                'read_at'    => $now,
            ])->toArray();
            ProjectMessageRead::upsert($rows, ['message_id', 'user_id']);
        }

        // Cursor upsert — base para detecção de não-lidos sem N+1
        DB::statement(
            "INSERT INTO project_user_reads (user_id, project_id, last_read_at)
             VALUES (?, ?, NOW())
             ON CONFLICT (user_id, project_id)
             DO UPDATE SET last_read_at = GREATEST(project_user_reads.last_read_at, EXCLUDED.last_read_at)",
            [$user->id, $project->id]
        );

        return response()->json(['marked' => $unreadIds->count()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['count' => 0]);
        }

        $query = ProjectMessage::query();

        if ($user->isCoordenador()) {
            $query->whereHas('project', fn($q) => $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $user->id)));
        }

        $count = $query
            ->where('user_id', '!=', $user->id)
            ->whereRaw(
                "project_messages.created_at > COALESCE((SELECT last_read_at FROM project_user_reads WHERE user_id = ? AND project_id = project_messages.project_id LIMIT 1), '1970-01-01'::timestamp)",
                [$user->id]
            )
            ->count();

        return response()->json(['count' => $count]);
    }

    public function unreadProjects(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['project_ids' => []]);
        }

        $query = ProjectMessage::query();

        if ($user->isCoordenador()) {
            $query->whereHas('project', fn($q) => $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $user->id)));
        }

        $projectIds = $query
            ->where('user_id', '!=', $user->id)
            ->whereRaw(
                "project_messages.created_at > COALESCE((SELECT last_read_at FROM project_user_reads WHERE user_id = ? AND project_id = project_messages.project_id LIMIT 1), '1970-01-01'::timestamp)",
                [$user->id]
            )
            ->pluck('project_id')
            ->unique()
            ->values();

        return response()->json(['project_ids' => $projectIds]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['items' => [], 'unread' => 0]);
        }

        $base = ProjectMessage::query()->where('user_id', '!=', $user->id);
        if ($user->isCoordenador()) {
            $base->whereHas('project', fn($q) => $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $user->id)));
        }

        $unreadExpr = "project_messages.created_at > COALESCE((SELECT last_read_at FROM project_user_reads WHERE user_id = ? AND project_id = project_messages.project_id LIMIT 1), '1970-01-01'::timestamp)";
        $unread = (clone $base)->whereRaw($unreadExpr, [$user->id])->count();

        // limit=10 no sino (mantém últimas 10 como histórico mesmo após ler); limit alto na tabela "Ver todas".
        $limit = min(max((int) $request->get('limit', 10), 1), 200);
        $reads = \Illuminate\Support\Facades\DB::table('project_user_reads')->where('user_id', $user->id)->pluck('last_read_at', 'project_id');

        $rows = $base
            ->with(['project:id,name,code,customer_id', 'project.customer:id,name', 'author:id,name'])
            ->latest()->limit($limit)->get()
            ->map(function ($msg) use ($reads) {
                $lr = $reads[$msg->project_id] ?? null;
                return [
                    'id'            => $msg->id,
                    'project_id'    => $msg->project_id,
                    'project_name'  => $msg->project?->name ?? '—',
                    'project_code'  => $msg->project?->code ?? '',
                    'customer_name' => $msg->project?->customer?->name ?? null,
                    'author_name'   => $msg->author?->name ?? '—',
                    'preview'       => mb_strimwidth(preg_replace('/@\[\d+:([^\]]+)\]/', '@$1', $msg->message), 0, 80, '…'),
                    'created_at'    => $msg->created_at,
                    'is_unread'     => !$lr || $msg->created_at->gt(\Illuminate\Support\Carbon::parse($lr)),
                ];
            });

        return response()->json(['items' => $rows, 'unread' => $unread]);
    }

    public function mentionableUsers(Request $request): JsonResponse
    {
        $user = $request->user();

        // Cliente não tem acesso ao chat de projeto → sem picker.
        if ($user->isCliente()) {
            return response()->json([], 403);
        }

        $projectId = $request->query('project_id');
        $project = $projectId ? Project::find((int) $projectId) : null;

        // Participantes do chat de projeto: coordenador(es) + executivo(s) (+ admin
        // como superusuário). Cliente nunca — chat de projeto é interno.
        if ($project) {
            $ids = collect($this->projectMentionableUserIds($project));
        } else {
            // Sem projeto no contexto: admins + coordenadores ativos.
            $ids = User::whereIn('type', ['admin', 'coordenador'])->where('enabled', true)->pluck('id');
        }

        $users = User::whereIn('id', $ids->unique()->filter()->values())
            ->where('enabled', true)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    /**
     * IDs dos participantes internos mencionáveis do projeto: admins ativos +
     * coordenador(es) + executivo de conta. Cliente NUNCA entra (chat é interno).
     * Fonte única usada pelo picker (mentionableUsers) e pela menção "Todos".
     */
    private function projectMentionableUserIds(Project $project): array
    {
        $ids = collect(User::where('type', 'admin')->where('enabled', true)->pluck('id'));
        $ids = $ids->merge($project->coordinators()->pluck('users.id'));
        if ($project->executivo_conta_id) {
            $ids->push((int) $project->executivo_conta_id);
        }

        return User::whereIn('id', $ids->unique()->filter()->values())
            ->where('enabled', true)
            ->pluck('id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    private function userCanAccessProject($user, Project $project): bool
    {
        if ($user->isCoordenador()) {
            return $project->coordinators()->where('users.id', $user->id)->exists();
        }
        if ($user->isCliente() && $user->customer_id) {
            return $project->customer_id === $user->customer_id;
        }
        return $project->consultants()->where('users.id', $user->id)->exists();
    }
}
