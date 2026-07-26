<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractMessage;
use App\Models\Attachment;
use App\Models\ContractMessageMention;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContractMessageController extends Controller
{
    use \App\Http\Controllers\Concerns\DispatchesChatMentions;

    public function index(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        // Cliente não tem acesso ao chat de contrato — fluxo dele encerra na requisição.
        if ($user->isCliente()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        if (!$this->canAccess($user, $contract)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $query = $contract->messages()->with(['author:id,name', 'attachments'])->orderBy('created_at');

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        // Cliente bloqueado — chat de contrato é interno-only.
        if ($user->isCliente()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        if (!$this->canAccess($user, $contract)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $request->validate([
            'message' => 'nullable|string|max:5000',
            'files'   => 'nullable|array|max:10',
            'files.*' => 'file|max:20480|mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip,rar,7z',
        ]);

        $text = $request->input('message', '');

        if (!$text && !$request->hasFile('files')) {
            return response()->json(['message' => 'Mensagem ou anexo obrigatório.'], 422);
        }

        // Toda mensagem em chat de contrato é interna — não existe mais a opção
        // de marcar visibility=client (cliente não acessa esse chat).
        $visibility = 'internal';

        $msg = ContractMessage::create([
            'contract_id' => $contract->id,
            'user_id'     => $user->id,
            'message'     => $text,
            'visibility'  => $visibility,
        ]);

        // FASE 11.7 (PR 7b) — Upload de anexos 100% via camada Attachment.
        if ($request->hasFile('files')) {
            $service = app(\App\Attachments\AttachmentService::class);
            foreach ($request->file('files') as $file) {
                $path = $file->store('contract-message-attachments', 'public');
                $service->registerExisting($user, [
                    'entity_type'   => 'CONTRACT_MESSAGE',
                    'entity_id'     => $msg->id,
                    'category'      => 'attachment',
                    'storage_path'  => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
                ]);
            }
        }

        $msg->load(['author:id,name', 'attachments']);

        // Parser @-mention (canônico + fallback plain-text via MentionParser).
        // Cliente NUNCA recebe mention de chat de contrato — não tem acesso a esse chat.
        $candidates = \App\Models\User::query()
            ->select('id', 'name')
            ->whereIn('type', ['admin', 'coordenador', 'consultor', 'parceiro_admin', 'administrativo'])
            ->get();
        $mentionedIds = \App\Services\MentionParser::extract((string) $msg->message, $candidates);
        foreach ($mentionedIds as $uid) {
            ContractMessageMention::firstOrCreate([
                'message_id'        => $msg->id,
                'mentioned_user_id' => $uid,
            ]);
        }

        // Marcação (@) → notifica a pessoa marcada (workflow chat.mention).
        try {
            $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
            $cardUrl = $base . '/contratos/pipeline?contract=' . $contract->id;
            $code = $contract->code ?? ($contract->project_code_preview ?? ('CTR-' . str_pad((string) $contract->id, 6, '0', STR_PAD_LEFT)));
            $title = $contract->project_name ?? 'Contrato';
            $role = match ($user->type) {
                'admin' => 'Admin', 'coordenador' => 'Coordenador', 'consultor' => 'Consultor',
                'parceiro_admin' => 'Parceiro', 'administrativo' => 'Administrativo', default => 'Equipe',
            };
            $this->dispatchMentionNotification('contract', $contract->id, $user, $mentionedIds, [
                'code'    => $code,
                'title'   => $title,
                'role'    => $role,
                'excerpt' => \Illuminate\Support\Str::limit($msg->message ?? '', 280),
                'openUrl' => $cardUrl . '&tab=chat',
                'cardUrl' => $cardUrl,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('chat mention notif contrato falhou', ['contract_id' => $contract->id, 'err' => $e->getMessage()]);
        }

        return response()->json($msg, 201);
    }

    public function downloadAttachment(Request $request, ContractMessage $message, Attachment $attachment): mixed
    {
        $user = $request->user();

        if ($user->isCliente()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        if (!$this->canAccess($user, $message->contract)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        // FASE 11.7 (PR 7b) — valida vínculo polimórfico.
        if ($attachment->entity_type !== 'CONTRACT_MESSAGE' || (int) $attachment->entity_id !== (int) $message->id) {
            return response()->json(['message' => 'Anexo não encontrado'], 404);
        }

        return Storage::disk('public')->download($attachment->storage_path, $attachment->original_name);
    }

    public function mentionableUsers(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        // Cliente não tem acesso ao chat de contrato → sem picker.
        if ($user->isCliente()) {
            return response()->json([], 403);
        }

        if (!$this->canAccess($user, $contract)) {
            return response()->json([], 403);
        }

        $ids = collect();

        // Executivo de conta: primeiro do contrato, depois do cliente
        if ($contract->executivo_conta_id) {
            $ids->push($contract->executivo_conta_id);
        } elseif ($contract->customer_id) {
            $customer = \App\Models\Customer::select('id', 'executive_id')->find($contract->customer_id);
            if ($customer?->executive_id) {
                $ids->push($customer->executive_id);
            }
        }

        // Coordenador kanban do contrato
        if ($contract->kanban_coordinator_id) {
            $ids->push($contract->kanban_coordinator_id);
        }

        // Cliente não entra no picker — chat de contrato é interno-only.

        // Exclui o próprio usuário da lista
        $ids = $ids->unique()->reject(fn($id) => $id === $user->id)->values();

        $users = User::whereIn('id', $ids)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    public function markRead(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if (!$this->canAccess($user, $contract)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $visibilityFilter = $user->isCliente() ? ['client'] : ['internal', 'client'];

        $unreadCount = ContractMessage::where('contract_id', $contract->id)
            ->where('user_id', '!=', $user->id)
            ->whereIn('visibility', $visibilityFilter)
            ->whereRaw(
                "created_at > COALESCE((SELECT last_read_at FROM contract_user_reads WHERE user_id = ? AND contract_id = ? LIMIT 1), '1970-01-01'::timestamp)",
                [$user->id, $contract->id]
            )
            ->count();

        DB::statement(
            "INSERT INTO contract_user_reads (user_id, contract_id, last_read_at)
             VALUES (?, ?, NOW())
             ON CONFLICT (user_id, contract_id)
             DO UPDATE SET last_read_at = GREATEST(contract_user_reads.last_read_at, EXCLUDED.last_read_at)",
            [$user->id, $contract->id]
        );

        return response()->json(['marked' => $unreadCount]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();

        $base = ContractMessage::query()->where('user_id', '!=', $user->id);
        if ($user->isCliente()) {
            $base->where('visibility', 'client')
                 ->whereHas('contract', fn($q) => $q->where('customer_id', $user->customer_id));
        } elseif ($user->isCoordenador()) {
            $base->whereHas('contract', fn($q) => $q->where('kanban_coordinator_id', $user->id));
        }

        $unreadExpr = "contract_messages.created_at > COALESCE((SELECT last_read_at FROM contract_user_reads WHERE user_id = ? AND contract_id = contract_messages.contract_id LIMIT 1), '1970-01-01'::timestamp)";
        $unread = (clone $base)->whereRaw($unreadExpr, [$user->id])->count();

        $limit = min(max((int) $request->get('limit', 10), 1), 200);
        $reads = \Illuminate\Support\Facades\DB::table('contract_user_reads')->where('user_id', $user->id)->pluck('last_read_at', 'contract_id');

        $rows = $base
            ->with(['contract:id,project_name,customer_id', 'contract.customer:id,name', 'author:id,name'])
            ->latest()->limit($limit)->get()
            ->map(function ($msg) use ($reads) {
                $lr = $reads[$msg->contract_id] ?? null;
                return [
                    'id'            => $msg->id,
                    'contract_id'   => $msg->contract_id,
                    'project_name'  => $msg->contract?->project_name ?? '—',
                    'customer_name' => $msg->contract?->customer?->name ?? '—',
                    'author_name'   => $msg->author?->name ?? '—',
                    'preview'       => mb_strimwidth(preg_replace('/@\[\d+:([^\]]+)\]/', '@$1', $msg->message ?? ''), 0, 80, '…'),
                    'created_at'    => $msg->created_at,
                    'is_unread'     => !$lr || $msg->created_at->gt(\Illuminate\Support\Carbon::parse($lr)),
                ];
            });

        return response()->json(['items' => $rows, 'unread' => $unread]);
    }

    public function unreadContracts(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ContractMessage::query()
            ->where('user_id', '!=', $user->id)
            ->whereRaw(
                "contract_messages.created_at > COALESCE((SELECT last_read_at FROM contract_user_reads WHERE user_id = ? AND contract_id = contract_messages.contract_id LIMIT 1), '1970-01-01'::timestamp)",
                [$user->id]
            );

        if ($user->isCliente()) {
            $query->where('visibility', 'client')
                  ->whereHas('contract', fn($q) => $q->where('customer_id', $user->customer_id));
        } elseif ($user->isCoordenador()) {
            $query->whereHas('contract', fn($q) =>
                $q->where('kanban_coordinator_id', $user->id)
            );
        }

        $contractIds = $query->pluck('contract_id')->unique()->values();

        return response()->json(['contract_ids' => $contractIds]);
    }

    private function canAccess(User $user, ?Contract $contract): bool
    {
        if (!$contract) return false;
        if ($user->isAdmin()) return true;
        if ($user->isCoordenador()) return true;
        if ($user->isCliente() && $user->customer_id === $contract->customer_id) return true;
        return false;
    }
}
