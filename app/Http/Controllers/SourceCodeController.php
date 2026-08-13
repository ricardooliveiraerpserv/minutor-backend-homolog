<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\HelpDeskTicket;
use App\Services\PermissionService;
use App\SourceCode\GitHubSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 1 — Solicitação de código-fonte (Help Desk). 1A = buscas (fonte e chamado).
 * Gate: quem tem 'source_code.request'. READ-ONLY (fonte via GitHub App; chamado no Postgres).
 */
class SourceCodeController extends Controller
{
    private function authorize(Request $request): void
    {
        $perms = PermissionService::for($request->user());
        abort_unless(in_array('*', $perms, true) || in_array('source_code.request', $perms, true), 403, 'Sem permissão para solicitar código-fonte.');
    }

    /** Busca fuzzy de fontes nos repositórios ATIVOS do cliente (fan-out, top 8). */
    public function search(Request $request, GitHubSourceService $svc): JsonResponse
    {
        $this->authorize($request);
        $customer = Customer::find((int) $request->query('customer_id'));
        abort_unless($customer, 404, 'Cliente não encontrado.');
        return response()->json($svc->search($customer, (string) $request->query('q', '')));
    }

    /** Chamados do cliente selecionado (server-side, sem carregar tudo). */
    public function tickets(Request $request): JsonResponse
    {
        $this->authorize($request);
        $customerId = (int) $request->query('customer_id');
        abort_unless($customerId, 422, 'customer_id obrigatório.');
        $q = trim((string) $request->query('q', ''));

        $rows = HelpDeskTicket::query()
            ->where('customer_id', $customerId)
            ->whereNull('merged_into_id')
            ->when($q !== '', function ($w) use ($q) {
                $like = '%' . $q . '%';
                $w->where(fn ($x) => $x->where('ticket_number', 'ilike', $like)->orWhere('subject', 'ilike', $like));
            })
            ->with(['status:id,label,color', 'assignee:id,name'])
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'ticket_number', 'subject', 'status_id', 'assignee_id', 'created_at']);

        return response()->json(['data' => $rows->map(fn ($t) => [
            'id'            => $t->id,
            'ticket_number' => $t->ticket_number,
            'subject'       => $t->subject,
            'status'        => $t->status?->label,
            'status_color'  => $t->status?->color,
            'assignee'      => $t->assignee?->name,
            'created_at'    => $t->created_at?->toIso8601String(),
        ])]);
    }
}
