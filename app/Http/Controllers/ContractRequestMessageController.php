<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\CardEnvolvido;
use App\Models\ContractRequest;
use App\Models\ContractRequestMessage;
use App\Models\ContractRequestMessageMention;
use App\Models\User;
use App\Notifications\CardChatMessageNotification;
use App\Services\CardEnvolvidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContractRequestMessageController extends Controller
{
    use \App\Http\Controllers\Concerns\DispatchesChatMentions;

    public function index(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        $user = auth()->user();

        // Cliente do mesmo customer da requisição acessa o chat — qualquer
        // cliente da empresa pode participar enquanto for requisição. (Antes
        // exigia criador OU watcher via clienteCanAccess, mas qualquer cliente
        // da empresa já vê a req no kanban, então deve poder conversar.)
        if ($user?->isCliente() && $user->customer_id !== $contractRequest->customer_id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        // Duas abas: 'client' = Comentários (cliente), 'internal' = Diário (equipe).
        $visibility = $this->resolveVisibility($request->query('visibility'));

        // Cliente só enxerga a aba Comentários — o Diário interno é invisível pra ele.
        if ($user->isCliente() && $visibility === 'internal') {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $query = $contractRequest->messages()
            ->where('visibility', $visibility)
            ->with(['author:id,name', 'attachments'])
            ->orderBy('created_at');

        // O canal de Comentários (client) continua vivo após virar projeto — cliente e equipe
        // seguem interagindo. Só o Diário interno da requisição congela (continuidade = Diário do
        // Projeto). Por isso NÃO há mais corte por req_decided_at no canal do cliente.

        return response()->json($query->get());
    }

    public function store(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        $user = auth()->user();

        // Mesmo critério do index: cliente do mesmo customer pode escrever.
        if ($user?->isCliente() && $user->customer_id !== $contractRequest->customer_id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        // Duas abas: cliente só posta em Comentários (client); Diário (internal) é da equipe.
        $visibility = $this->resolveVisibility($request->input('visibility'));
        if ($user->isCliente()) {
            $visibility = 'client';
        }

        // Depois que a requisição vira projeto (req_decided_at preenchido), o DIÁRIO INTERNO da
        // requisição congela — a continuidade interna passa a ser o Diário do Projeto. Mas os
        // COMENTÁRIOS (client) seguem vivos: cliente e equipe continuam interagindo no projeto.
        if ($contractRequest->req_decided_at !== null && $visibility === 'internal') {
            return response()->json([
                'message' => 'A requisição virou projeto. O diário interno virou histórico — use o Diário do Projeto.',
            ], 403);
        }

        $request->validate([
            'message'    => 'nullable|string|max:2000',
            'visibility' => 'nullable|in:client,internal',
            'files'      => 'nullable|array|max:10',
            'files.*'    => 'file|max:20480',
        ]);

        // Comentários do cliente: aceita QUALQUER tipo de arquivo (foto de celular
        // HEIC/HEIF, vídeos, etc.) — a whitelist `mimes:` antiga barrava a foto do
        // iPhone e o cliente "não conseguia anexar nada". Mantém a proteção anti-RCE/XSS
        // por DENYLIST de extensões executáveis/scripts (inclui svg por XSS).
        $blockedExt = [
            'php','phtml','phar','php3','php4','php5','php7','pht','pgif',
            'exe','com','bat','cmd','sh','bash','csh','ksh','run',
            'js','mjs','cjs','jar','msi','dll','scr','vbs','vbe','ps1','psm1',
            'html','htm','xhtml','shtml','svg','svgz','hta','wsf','wsh',
            'reg','cpl','asp','aspx','jsp','cgi','app','deb','apk',
        ];
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $ext = strtolower($file->getClientOriginalExtension());
                if (in_array($ext, $blockedExt, true)) {
                    return response()->json([
                        'message' => 'Tipo de arquivo não permitido por segurança: .' . $ext,
                    ], 422);
                }
            }
        }

        // ConvertEmptyStringsToNull transforma "" em null; o cast garante string
        // (anexo sem texto não pode inserir null na coluna NOT NULL message).
        $text = (string) $request->input('message', '');
        if (!$text && !$request->hasFile('files')) {
            return response()->json(['message' => 'Mensagem ou anexo obrigatório.'], 422);
        }

        $msg = ContractRequestMessage::create([
            'contract_request_id' => $contractRequest->id,
            'user_id'             => $user->id,
            'message'             => $text,
            'visibility'          => $visibility,
        ]);

        // FASE 11.7 (PR 7b) — Upload de anexos 100% via camada Attachment.
        if ($request->hasFile('files')) {
            $service = app(\App\Attachments\AttachmentService::class);
            foreach ($request->file('files') as $file) {
                $path = $file->store('req-message-attachments', 'public');
                $service->registerExisting($user, [
                    'entity_type'   => 'REQUEST_MESSAGE',
                    'entity_id'     => $msg->id,
                    'category'      => 'attachment',
                    'storage_path'  => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
                ]);
            }
        }

        $msg->load(['author:id,name', 'attachments']);

        // Parser @-mention token @[id:Nome] (espelha ProjectMessageController)
        $mentionedIds = $this->persistMentions($msg);

        // Diário interno NUNCA vaza pro cliente: descarta qualquer menção a cliente.
        if ($visibility === 'internal' && !empty($mentionedIds)) {
            $clientIds = User::whereIn('id', $mentionedIds)->where('type', 'cliente')->pluck('id')->all();
            $mentionedIds = array_values(array_diff($mentionedIds, $clientIds));
        }

        // Fase card-envolvidos: notifica envolvidos do card (cliente sai automaticamente
        // se req_decided_at preenchido). Best-effort — falha em mail não bloqueia chat.
        // No Diário interno pulamos o broadcast pros envolvidos (que pode incluir o
        // cliente) — só notificamos os @-mencionados, já filtrados pra equipe.
        try {
            $this->dispatchChatNotification($contractRequest, $msg, $user, $mentionedIds, $visibility);
        } catch (\Throwable $e) {
            \Log::warning('chat notif req falhou', ['req_id' => $contractRequest->id, 'err' => $e->getMessage()]);
        }

        return response()->json($msg, 201);
    }

    private function persistMentions(ContractRequestMessage $msg): array
    {
        // Candidatos = usuários com acesso à req (cliente da req + admins/coords)
        $req = $msg->request ?? \App\Models\ContractRequest::find($msg->contract_request_id);
        $candidates = \App\Models\User::query()
            ->select('id', 'name')
            ->where(function ($q) use ($req) {
                $q->whereIn('type', ['admin', 'coordenador', 'consultor', 'parceiro_admin', 'administrativo']);
                if ($req?->customer_id) {
                    $q->orWhere(fn ($q2) => $q2->where('type', 'cliente')->where('customer_id', $req->customer_id));
                }
            })
            ->get();

        $userIds = \App\Services\MentionParser::extract((string) $msg->message, $candidates);
        foreach ($userIds as $uid) {
            ContractRequestMessageMention::firstOrCreate([
                'message_id'        => $msg->id,
                'mentioned_user_id' => $uid,
            ]);
        }
        return $userIds;
    }

    private function dispatchChatNotification(ContractRequest $req, ContractRequestMessage $msg, User $author, array $mentionedIds = [], string $visibility = 'client'): void
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $cardUrl = $base . '/contratos/pipeline?req=' . $req->id;
        // tab=chat (query) é mais confiável que hash #chat em client-side nav (Next 16)
        $openUrl = $cardUrl . '&tab=chat';
        $code = $req->code ?? ('REQ-' . str_pad((string) $req->id, 6, '0', STR_PAD_LEFT));
        $title = $req->title ?? ($req->subject ?? 'Requisição');
        // Texto COMPLETO no e-mail (antes truncava em 280). Cap alto como salvaguarda.
        $excerpt = Str::limit($msg->message ?? '', 5000);
        $customerName = $req->customer?->name ?? '';

        $mkNotif = fn () => (new CardChatMessageNotification(
            cardType:       CardEnvolvido::TYPE_REQUEST,
            cardCode:       $code,
            cardTitle:      $title,
            authorName:     $author->name,
            authorRole:     $this->userRoleLabel($author),
            messageExcerpt: $excerpt,
            openUrl:        $openUrl,
            cardUrl:        $cardUrl,
            recipientName:  'você',
            customerName:   $customerName,
        ));

        // 1) Mensagem no chat → envolvidos do card.
        // Diário interno: pula o broadcast pros envolvidos (que resolve contatos do
        // cliente). Só os @-mencionados (equipe) são notificados, no passo 2.
        $chatTo = [];
        $rcpt = ['to' => [], 'cc' => []];
        if ($visibility !== 'internal') {
            $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('card.chat_message.request', [
                'card'      => ['type' => CardEnvolvido::TYPE_REQUEST, 'id' => $req->id],
                'actor'     => $author,
                'mentioned' => $mentionedIds,
            ]);
            $chatTo = $rcpt['to'] ?? [];
            if (!empty($chatTo)) {
                Notification::route('mail', $chatTo)->notify($mkNotif()->withCc($rcpt['cc'] ?? []));
            }
        }

        // 2) Marcação (@) → pessoa marcada, sem duplicar quem já recebeu acima.
        $this->dispatchMentionNotification(CardEnvolvido::TYPE_REQUEST, $req->id, $author, $mentionedIds, [
            'code' => $code, 'title' => $title, 'role' => $this->userRoleLabel($author), 'excerpt' => $excerpt, 'openUrl' => $openUrl, 'cardUrl' => $cardUrl, 'customer' => $customerName,
        ], array_merge($chatTo, $rcpt['cc'] ?? []));
    }

    private function userRoleLabel(User $u): string
    {
        return match ($u->type) {
            'admin' => 'Admin', 'coordenador' => 'Coordenador', 'consultor' => 'Consultor',
            'cliente' => 'Cliente', 'parceiro_admin' => 'Parceiro', 'administrativo' => 'Administrativo',
            default => 'Equipe',
        };
    }

    public function downloadAttachment(Request $request, ContractRequestMessage $message, Attachment $attachment): mixed
    {
        $user = auth()->user();

        if ($user->isCliente() && $user->customer_id !== $message->request?->customer_id) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        // FASE 11.7 (PR 7b) — valida vínculo polimórfico.
        if ($attachment->entity_type !== 'REQUEST_MESSAGE' || (int) $attachment->entity_id !== (int) $message->id) {
            return response()->json(['message' => 'Anexo não encontrado'], 404);
        }

        return Storage::disk('public')->download($attachment->storage_path, $attachment->original_name);
    }

    public function mentionableUsers(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        $user = auth()->user();

        // Mesmo critério de index/store: cliente do mesmo customer acessa.
        // (Antes exigia criador OU watcher via clienteCanAccess — restritivo demais
        // e inconsistente: cliente conseguia ler/escrever mensagens mas não buscava
        // a lista de menção, quebrando o @-picker em homolog.)
        if ($user?->isCliente() && $user->customer_id !== $contractRequest->customer_id) {
            return response()->json([], 403);
        }

        $visibility = $this->resolveVisibility($request->query('visibility'));
        if ($user->isCliente() && $visibility === 'internal') {
            return response()->json([], 403);
        }

        $customer = $contractRequest->customer;
        $executiveId = $customer?->executive_id;

        $admins = User::query()
            ->where('type', 'admin')
            ->where('enabled', true)
            ->select('id', 'name')
            ->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => 'admin']);

        $executivo = $executiveId
            ? User::query()
                ->where('id', $executiveId)
                ->where('enabled', true)
                ->select('id', 'name')
                ->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => 'executivo'])
            : collect();

        if ($visibility === 'internal') {
            // Diário interno: equipe (admin/administrativo/coord/consultor/parceiro) + executivo.
            // Cliente NUNCA entra no @-picker do Diário.
            $team = User::query()
                ->whereIn('type', ['administrativo', 'coordenador', 'consultor', 'parceiro_admin'])
                ->where('enabled', true)
                ->select('id', 'name', 'type')
                ->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->type]);

            return response()->json(
                $admins->concat($executivo)->concat($team)->unique('id')->sortBy('name')->values()
            );
        }

        // Comentários (client): chat é entre cliente e executivo da conta. Admin participa.
        $clientes = User::query()
            ->where('type', 'cliente')
            ->where('customer_id', $contractRequest->customer_id)
            ->where('enabled', true)
            ->select('id', 'name')
            ->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => 'cliente']);

        $users = $admins
            ->concat($executivo)
            ->concat($clientes)
            ->unique('id')
            ->sortBy('name')
            ->values();

        return response()->json($users);
    }

    /** Normaliza o parâmetro de aba; qualquer valor inválido cai em 'client' (Comentários). */
    private function resolveVisibility(?string $value): string
    {
        return $value === 'internal' ? 'internal' : 'client';
    }

    /**
     * Cliente acessa a requisição/chat se for do mesmo customer E (criador OU watcher).
     * Internos (admin/coord/consultor) sempre passam.
     */
    private function clienteCanAccess(?User $user, ContractRequest $req): bool
    {
        if (!$user) return false;
        if (!$user->isCliente()) return true;
        if ($user->customer_id !== $req->customer_id) return false;
        if ((int) $req->created_by_id === (int) $user->id) return true;
        return $req->watchers()->where('user_id', $user->id)->exists();
    }
}
