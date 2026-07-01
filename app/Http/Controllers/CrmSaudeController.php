<?php

namespace App\Http\Controllers;

use App\Models\CrmAccountHealthSnapshot;
use App\Models\Customer;
use App\Services\AccountHealthService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Roadmap Fase 1 — Saúde da Conta: painel executivo + histórico. */
class CrmSaudeController extends Controller
{
    public function __construct(private AccountHealthService $health) {}

    private function authorizeView(): void
    {
        $perms = auth()->check() ? PermissionService::for(auth()->user()) : [];
        abort_unless(in_array('*', $perms, true) || in_array('crm.view', $perms, true), 403, 'Sem acesso à Saúde da Conta.');
    }

    /** Painel executivo: clientes por saúde (Crítico → Atenção → Saudável) com filtros. */
    public function painel(Request $request): JsonResponse
    {
        $this->authorizeView();
        $customers = Customer::query()->whereNull('deleted_at')
            ->with(['crmProfile:customer_id,segment,region', 'executive:id,name'])
            ->when($request->filled('executivo_id'), fn ($q) => $q->where('executive_id', $request->executivo_id))
            ->when($request->filled('crm_status'), fn ($q) => $q->where('crm_status', $request->crm_status))
            ->when($request->filled('segmento'), fn ($q) => $q->whereHas('crmProfile', fn ($p) => $p->where('segment', $request->segmento)))
            ->when($request->filled('regiao'), fn ($q) => $q->whereHas('crmProfile', fn ($p) => $p->where('region', $request->regiao)))
            ->get();

        // Último snapshot por cliente (bulk, sem N+1); fallback p/ cálculo ao vivo.
        $latest = CrmAccountHealthSnapshot::whereIn('customer_id', $customers->pluck('id'))
            ->orderByDesc('id')->get()->groupBy('customer_id')->map->first();

        $peso = ['critico' => 0, 'atencao' => 1, 'saudavel' => 2];
        $linhas = $customers->map(function ($c) use ($latest, $peso) {
            $snap = $latest[$c->id] ?? null;
            $r = $snap ? ['score' => $snap->score, 'status' => $snap->status, 'motivos' => $snap->motivos]
                       : $this->health->compute($c);   // ao vivo (sem margem) antes do 1º snapshot
            return [
                'customer_id' => $c->id, 'name' => $c->name, 'crm_status' => $c->crm_status,
                'segmento' => $c->crmProfile?->segment, 'regiao' => $c->crmProfile?->region,
                'executivo' => $c->executive?->name,
                'status' => $r['status'], 'score' => $r['score'],
                'motivos' => collect($r['motivos'] ?? [])->pluck('texto')->values(),
            ];
        })->sortBy([fn ($a, $b) => ($peso[$a['status']] ?? 9) <=> ($peso[$b['status']] ?? 9) ?: $b['score'] <=> $a['score']])->values();

        return response()->json(['data' => [
            'resumo' => [
                'critico'  => $linhas->where('status', 'critico')->count(),
                'atencao'  => $linhas->where('status', 'atencao')->count(),
                'saudavel' => $linhas->where('status', 'saudavel')->count(),
            ],
            'clientes' => $linhas,
        ]]);
    }

    /** Histórico de evolução da saúde (snapshots) de uma empresa. */
    public function historico(Customer $customer): JsonResponse
    {
        $this->authorizeView();
        $hist = CrmAccountHealthSnapshot::where('customer_id', $customer->id)
            ->orderByDesc('id')->limit(12)->get(['status', 'score', 'created_at'])
            ->map(fn ($s) => ['status' => $s->status, 'score' => $s->score, 'when' => $s->created_at])->reverse()->values();
        return response()->json(['data' => $hist]);
    }
}
