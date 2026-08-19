<?php

namespace App\Http\Controllers;

use App\Models\SourceDocCustomerSetting;
use App\Models\SourceDocSourceRequest;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central de Fontes — administração por empresa: detentor de fonte, ocultar da Central e
 * solicitações de fonte. Escopo por cliente respeitado (deny-by-default). Sem IA/motor.
 */
class SourceDocCustomerAdminController extends Controller
{
    public function __construct(private SourceDocCustomerScope $scope)
    {
    }

    /** PUT /source-docs/customers/{customer}/settings {own_source?, hidden?} — detentor / ocultar. */
    public function updateSettings(Request $request, int $customer): JsonResponse
    {
        if (! $this->scope->canAccessCustomerId($request->user(), $customer)) {
            return response()->json(['message' => 'Cliente fora do seu escopo.'], 404);
        }
        $data = $request->validate([
            'own_source' => ['sometimes', 'boolean'],
            'hidden' => ['sometimes', 'boolean'],
        ]);
        if ($data === []) {
            return response()->json(['message' => 'Nada para atualizar.'], 422);
        }
        $setting = SourceDocCustomerSetting::query()->firstOrNew(['customer_id' => $customer]);
        foreach ($data as $k => $v) {
            $setting->{$k} = (bool) $v;
        }
        $setting->updated_by = $request->user()?->id;
        $setting->save();

        return response()->json(['data' => [
            'customer_id' => $customer,
            'own_source' => (bool) $setting->own_source,
            'hidden' => (bool) $setting->hidden,
        ]]);
    }

    /** POST /source-docs/source-requests {customer_id?, repository?, note} — registra solicitação. */
    public function storeRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'repository' => ['nullable', 'string', 'max:255'],
            'ticket' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'in:baixa,media,alta'],
            'scope_type' => ['nullable', 'in:source,folder,repository'],
            'paths' => ['nullable', 'array', 'max:500'],
            'paths.*' => ['string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        if (empty($data['customer_id']) && empty($data['repository']) && empty($data['note']) && empty($data['paths'])) {
            return response()->json(['message' => 'Informe ao menos a empresa, o repositório, a pasta/fontes ou uma observação.'], 422);
        }
        if (! empty($data['customer_id']) && ! $this->scope->canAccessCustomerId($request->user(), (int) $data['customer_id'])) {
            return response()->json(['message' => 'Cliente fora do seu escopo.'], 404);
        }

        $req = SourceDocSourceRequest::create([
            'customer_id' => $data['customer_id'] ?? null,
            'repository' => $data['repository'] ?? null,
            'ticket' => $data['ticket'] ?? null,
            'priority' => $data['priority'] ?? 'media',
            'scope_type' => $data['scope_type'] ?? 'repository',
            'paths' => $data['paths'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'open',
            'requested_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $req], 201);
    }

    /** GET /source-docs/open-tickets?customer_id= — chamados ABERTOS da empresa (Help Desk), p/ o seletor. */
    public function openTickets(Request $request): JsonResponse
    {
        $customerId = (int) $request->query('customer_id', 0);
        if ($customerId <= 0 || ! $this->scope->canAccessCustomerId($request->user(), $customerId)) {
            return response()->json(['data' => []]);
        }
        $tickets = \App\Models\HelpDeskTicket::query()
            ->where('customer_id', $customerId)
            ->whereHas('status', fn ($s) => $s->where('is_open', true))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'ticket_number', 'subject']);

        return response()->json(['data' => $tickets]);
    }

    /** GET /source-docs/gmud-commits — versões de fonte criadas via GMUD (commit + ticket), escopado. */
    public function gmudCommits(Request $request): JsonResponse
    {
        $q = \App\Models\SourceDocVersion::query()
            ->join('source_docs', 'source_docs.id', '=', 'source_doc_versions.source_doc_id')
            ->leftJoin('customers', 'customers.id', '=', 'source_docs.customer_id')
            ->leftJoin('helpdesk_tickets as ht', 'ht.ticket_number', '=', 'source_doc_versions.ticket_number')
            ->where(fn ($w) => $w->whereNotNull('source_doc_versions.gmud_id')->orWhereNotNull('source_doc_versions.ticket_number'))
            ->when($request->filled('customer_id'), fn ($qq) => $qq->where('source_docs.customer_id', (int) $request->query('customer_id')))
            ->when($request->filled('q'), fn ($qq) => $qq->where('source_docs.filename', 'ilike', '%' . trim((string) $request->query('q')) . '%'))
            ->when($request->filled('from'), fn ($qq) => $qq->whereDate('source_doc_versions.created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($qq) => $qq->whereDate('source_doc_versions.created_at', '<=', $request->query('to')));
        $this->scope->applyScope($q, $request->user(), 'source_docs.customer_id');
        $rows = $q->orderByDesc('source_doc_versions.created_at')->limit(300)->get([
            'source_doc_versions.id', 'source_doc_versions.source_doc_id', 'source_doc_versions.ticket_number',
            'source_doc_versions.gmud_id', 'source_doc_versions.source_commit_sha', 'source_doc_versions.responsavel',
            'source_doc_versions.diff_summary', 'source_doc_versions.created_at',
            'source_docs.filename', 'source_docs.repository', 'source_docs.owner', 'source_docs.customer_id',
            'customers.name as customer_name',
            'ht.id as hd_ticket_id', 'ht.subject as hd_subject',
        ]);

        return response()->json(['data' => $rows]);
    }

    /** GET /source-docs/source-requests?status= — lista (gestão). */
    public function listRequests(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'open');
        $q = SourceDocSourceRequest::query()
            ->leftJoin('customers', 'customers.id', '=', 'source_doc_source_requests.customer_id')
            ->leftJoin('users', 'users.id', '=', 'source_doc_source_requests.requested_by')
            ->leftJoin('helpdesk_tickets as ht', 'ht.ticket_number', '=', 'source_doc_source_requests.ticket')
            ->when($status !== 'all', fn ($qq) => $qq->where('source_doc_source_requests.status', $status))
            ->when($request->filled('customer_id'), fn ($qq) => $qq->where('source_doc_source_requests.customer_id', (int) $request->query('customer_id')))
            ->orderByDesc('source_doc_source_requests.created_at')
            ->limit(300)
            ->get(['source_doc_source_requests.*', 'customers.name as customer_name', 'users.name as requester_name', 'ht.id as hd_ticket_id', 'ht.subject as hd_subject']);

        return response()->json(['data' => $q]);
    }

    /** PATCH /source-docs/source-requests/{id} {status} — atender / rejeitar / reabrir. */
    public function updateRequest(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,provisioned,rejected']]);
        $req = SourceDocSourceRequest::query()->find($id);
        if (! $req) {
            return response()->json(['message' => 'Solicitação não encontrada.'], 404);
        }
        $req->status = $data['status'];
        $req->save();

        return response()->json(['data' => ['id' => $req->id, 'status' => $req->status]]);
    }
}
