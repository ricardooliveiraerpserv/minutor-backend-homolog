<?php

namespace App\Http\Controllers;

use App\Models\ContractRequest;
use App\Models\ContractRequestWatcher;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user     = auth()->user();
        $query    = ContractRequest::with([
                'customer:id,name',
                'createdBy:id,name',
                'reviewedBy:id,name',
                'contract:id,project_name',
                'watchers:id,contract_request_id,user_id,email',
            ])
            ->when($request->query('status'), fn($q) => $q->where('status', $request->query('status')))
            ->when($request->query('urgencia'), fn($q) => $q->where('nivel_urgencia', $request->query('urgencia')))
            ->when($request->query('search'), function ($q) use ($request) {
                $s = '%' . $request->query('search') . '%';
                $q->where(fn($qb) => $qb->where('area_requisitante', 'ilike', $s)
                    ->orWhere('descricao', 'ilike', $s)
                    ->orWhereHas('customer', fn($c) => $c->where('name', 'ilike', $s)));
            });

        // Cliente vê apenas requisições do próprio customer onde foi criador OU está em cópia (watcher).
        if ($user?->isCliente() && $user->customer_id) {
            $query->where('customer_id', $user->customer_id)
                ->where(function ($q) use ($user) {
                    $q->where('created_by_id', $user->id)
                      ->orWhereHas('watchers', fn($w) => $w->where('user_id', $user->id));
                });
        }

        $query->orderByRaw("CASE nivel_urgencia
            WHEN 'altissimo' THEN 0
            WHEN 'alto'      THEN 1
            WHEN 'medio'     THEN 2
            WHEN 'baixo'     THEN 3
            ELSE 4 END")
            ->orderBy('created_at', 'desc');

        return response()->json($query->paginate($request->query('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'area_requisitante'      => 'required|string|max:255',
            'project_name'           => 'required|string|max:255',
            'product_owner'          => 'required|string|max:255',
            'modulo_tecnologia'      => 'required|string|max:255',
            'tipo_necessidade'       => 'required|string|in:' . implode(',', array_keys(ContractRequest::TIPOS)),
            'tipo_necessidade_outro' => 'nullable|string|max:255',
            'nivel_urgencia'         => 'required|string|in:' . implode(',', array_keys(ContractRequest::URGENCIAS)),
            'descricao'              => 'required|string',
            'cenario_atual'          => 'required|string',
            'cenario_desejado'       => 'required|string',
            'cc_emails'              => 'nullable|array|max:20',
            'cc_emails.*'            => 'email:rfc',
        ]);

        // Resolve customer_id: cliente usa o próprio, admin pode passar
        $customerId = $user?->isCliente()
            ? $user->customer_id
            : ($request->input('customer_id') ?? $user->customer_id);

        if (!$customerId) {
            return response()->json(['message' => 'Cliente não identificado.'], 422);
        }

        $ccEmails = collect($validated['cc_emails'] ?? [])
            ->map(fn($e) => strtolower(trim($e)))
            ->filter()
            ->unique()
            ->values();

        $req = DB::transaction(function () use ($validated, $customerId, $user, $ccEmails) {
            $createPayload = collect($validated)->except('cc_emails')->all();

            $req = ContractRequest::create(array_merge($createPayload, [
                'customer_id'   => $customerId,
                'created_by_id' => $user->id,
                'status'        => ContractRequest::STATUS_PENDENTE,
            ]));

            \App\Models\ContractRequestKanbanLog::create([
                'contract_request_id' => $req->id,
                'from_column'         => null,
                'to_column'           => $req->kanban_column ?? 'backlog',
                'moved_by_id'         => $user->id,
            ]);

            if ($ccEmails->isNotEmpty()) {
                $resolved = $this->resolveUsersByEmail($ccEmails->all(), $customerId);
                foreach ($ccEmails as $email) {
                    if ($email === strtolower((string) $user->email)) continue; // criador não vira watcher
                    ContractRequestWatcher::create([
                        'contract_request_id' => $req->id,
                        'email'               => $email,
                        'user_id'             => $resolved[$email] ?? null,
                    ]);
                }
            }

            return $req;
        });

        $req = $req->load([
            'customer:id,name,executive_id',
            'createdBy:id,name,email',
            'watchers:id,contract_request_id,user_id,email',
            'watchers.user:id,name,email',
        ]);

        // Notifica cliente + executivo da conta + watchers (best-effort)
        try {
            app(\App\Services\ContractRequestNotifier::class)->created($req);
        } catch (\Throwable $e) {
            \Log::warning('req lifecycle (created) falhou', ['req_id' => $req->id, 'err' => $e->getMessage()]);
        }

        return response()->json($req, 201);
    }

    public function show(ContractRequest $contractRequest): JsonResponse
    {
        $user = auth()->user();
        if ($user?->isCliente()) {
            if ($user->customer_id !== $contractRequest->customer_id) {
                return response()->json(['message' => 'Sem permissão.'], 403);
            }
            $isCreator = (int) $contractRequest->created_by_id === (int) $user->id;
            $isWatcher = $contractRequest->watchers()->where('user_id', $user->id)->exists();
            if (!$isCreator && !$isWatcher) {
                return response()->json(['message' => 'Sem permissão.'], 403);
            }
        }

        return response()->json($contractRequest->load([
            'customer:id,name',
            'createdBy:id,name',
            'reviewedBy:id,name',
            'contract:id,project_name',
            'watchers:id,contract_request_id,user_id,email',
            'watchers.user:id,name,email',
        ]));
    }

    public function review(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        $validated = $request->validate([
            'status'          => 'required|in:em_analise,aprovado,recusado',
            'notas_revisao'   => 'nullable|string',
        ]);

        $contractRequest->update(array_merge($validated, [
            'reviewed_by_id' => auth()->id(),
            'reviewed_at'    => now(),
        ]));

        return response()->json($contractRequest->fresh(['customer:id,name', 'reviewedBy:id,name']));
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'tipos'     => ContractRequest::TIPOS,
            'urgencias' => ContractRequest::URGENCIAS,
        ]);
    }

    /**
     * Resolve uma lista de e-mails contra usuários cadastrados do mesmo customer.
     * Usado pelo formulário de requisição para feedback live: ✓ vinculado vs sem cadastro.
     *
     * Body: { "emails": ["a@x.com", ...], "customer_id": 123 (admin/coord) }
     * Resp: { "results": [{ "email": "a@x.com", "user": { "id": 1, "name": "..." } | null }] }
     */
    /**
     * Sugestões de contatos para o campo "Em cópia" da requisição: contatos do CLIENTE
     * selecionado (users type=cliente do customer) + equipe/consultores ERPSERV (internos),
     * filtrados por nome/e-mail. Cliente logado usa o próprio customer.
     *
     * Query: ?customer_id=123&q=alex  → { "data": [{name,email,kind: 'cliente'|'erpserv'}] }
     */
    public function contactSuggestions(Request $request): JsonResponse
    {
        $q    = trim((string) $request->query('q', ''));
        $user = auth()->user();
        $customerId = $user?->isCliente()
            ? $user->customer_id
            : ($request->query('customer_id') ? (int) $request->query('customer_id') : null);

        $applyQ = function ($query) use ($q) {
            if ($q !== '') {
                $like = '%' . mb_strtolower($q) . '%';
                $query->where(function ($w) use ($like) {
                    $w->whereRaw('LOWER(name) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                });
            }
            return $query;
        };

        $out = collect();

        // 1) Contatos do CLIENTE da requisição (só o cliente do card). Usuários de OUTROS
        //    clientes NÃO entram — não vazar contatos entre organizações.
        if ($customerId) {
            $applyQ(User::where('type', 'cliente')->where('customer_id', $customerId)->whereNotNull('email'))
                ->orderBy('name')->limit(50)->get(['id', 'name', 'email'])
                ->each(fn ($u) => $out->push(['name' => $u->name, 'email' => $u->email, 'kind' => 'cliente']));
        }

        // 2) Equipe/consultores ERPSERV (internos) — todos.
        $applyQ(User::whereIn('type', ['consultor', 'coordenador', 'administrativo', 'admin'])->whereNotNull('email'))
            ->orderBy('name')->limit(80)->get(['id', 'name', 'email'])
            ->each(fn ($u) => $out->push(['name' => $u->name, 'email' => $u->email, 'kind' => 'erpserv']));

        // 3) Parceiros (parceiro_admin) — todos. Trazidos independentemente do cliente.
        $applyQ(User::where('type', 'parceiro_admin')->whereNotNull('email'))
            ->orderBy('name')->limit(80)->get(['id', 'name', 'email'])
            ->each(fn ($u) => $out->push(['name' => $u->name, 'email' => $u->email, 'kind' => 'parceiro']));

        $data = $out->unique('email')->values();
        return response()->json(['data' => $data]);
    }

    public function resolveEmails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'emails'      => 'required|array|max:20',
            'emails.*'    => 'email:rfc',
            'customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        $user = auth()->user();
        $customerId = $user?->isCliente()
            ? $user->customer_id
            : ($validated['customer_id'] ?? null);

        if (!$customerId) {
            return response()->json(['message' => 'customer_id é obrigatório.'], 422);
        }

        $emails = collect($validated['emails'])
            ->map(fn($e) => strtolower(trim($e)))
            ->filter()
            ->unique()
            ->values();

        $resolved = $this->resolveUsersByEmail($emails->all(), (int) $customerId);
        $byId = User::whereIn('id', array_values($resolved))->get(['id', 'name', 'email'])->keyBy('id');

        $results = $emails->map(function ($email) use ($resolved, $byId) {
            $uid = $resolved[$email] ?? null;
            return [
                'email' => $email,
                'user'  => $uid ? [
                    'id'   => $byId[$uid]->id,
                    'name' => $byId[$uid]->name,
                ] : null,
            ];
        })->values();

        return response()->json(['results' => $results]);
    }

    /**
     * Lookup case-insensitive: retorna [emailLowercase => userId] para usuários cliente
     * do mesmo customer. Não cria nada — só resolve.
     */
    private function resolveUsersByEmail(array $emails, int $customerId): array
    {
        if (empty($emails)) return [];

        $users = User::query()
            ->where('type', 'cliente')
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(email) IN (' . implode(',', array_fill(0, count($emails), '?')) . ')', $emails)
            ->get(['id', 'email']);

        $map = [];
        foreach ($users as $u) {
            $map[strtolower($u->email)] = $u->id;
        }
        return $map;
    }

    /** Exclui uma REQUISIÇÃO (soft-delete) registrando o log de auditoria (quem/quando/o quê/motivo). Admin. */
    public function destroy(Request $request, ContractRequest $contractRequest): JsonResponse
    {
        if (!$request->user()?->isAdmin()) abort(403);

        \App\Models\ContractDeletionLog::create([
            'contract_id'     => $contractRequest->id, // id da requisição
            'contract_name'   => \Illuminate\Support\Str::limit((string) $contractRequest->descricao, 120) ?: ('Requisição #' . $contractRequest->id),
            'customer_name'   => optional($contractRequest->customer)->name,
            'kanban_status'   => $contractRequest->kanban_column ?? $contractRequest->status,
            'deleted_by'      => optional($request->user())->id,
            'deleted_by_name' => optional($request->user())->name,
            'reason'          => trim((string) $request->input('reason')) ?: null,
            'snapshot'        => $contractRequest->toArray(),
            'company_id'      => null,
        ]);

        $contractRequest->delete();
        return response()->json(null, 204);
    }
}
