<?php

namespace App\Http\Controllers;

use App\Models\CrmAccountHealthSnapshot;
use App\Models\Customer;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap Fase 4 — Carteira do Executivo: visão consolidada da carteira (clientes,
 * receita, margem, renovações, follow-ups, projetos em risco, críticos, sem interação).
 * Reusa Saúde da Conta (snapshots) + agregados existentes. Empresa única.
 */
class CrmCarteiraController extends Controller
{
    use \App\Http\Traits\FiltersByActiveCompany;

    private function authorizeView(): array
    {
        $u = auth()->user();
        $perms = $u ? PermissionService::for($u) : [];
        abort_unless(in_array('*', $perms, true) || in_array('crm.view', $perms, true), 403, 'Sem acesso à carteira.');
        return $perms;
    }

    public function index(Request $request): JsonResponse
    {
        $perms = $this->authorizeView();
        $u = auth()->user();
        $podeVerTodos = in_array('*', $perms, true) || in_array('crm.opportunities.view_all', $perms, true) || in_array('crm.manage', $perms, true);
        // Executivo vê a própria carteira; gestor pode escolher outro (ou ver todas).
        // Gestor escolhe o responsavel (ou todos); nao-gestor SEMPRE ve so a propria carteira (param ignorado).
        $execId = $podeVerTodos ? ($request->filled('executivo_id') ? (int) $request->executivo_id : null) : $u->id;

        $comercial = ['lead', 'prospect', 'cliente', 'em_renovacao'];
        $customers = Customer::whereNull('deleted_at')->whereIn('crm_status', $comercial)
            ->when($execId, fn ($q) => $q->where('executive_id', $execId))
            ->with(['crmProfile:customer_id,segment,region,ultima_interacao_at', 'executive:id,name'])
            ->when($request->filled('segmento'), fn ($q) => $q->whereHas('crmProfile', fn ($p) => $p->where('segment', $request->segmento)))
            ->when($request->filled('regiao'), fn ($q) => $q->whereHas('crmProfile', fn ($p) => $p->where('region', $request->regiao)))
            ->when($request->filled('crm_status'), fn ($q) => $q->where('crm_status', $request->crm_status))
            ->get();
        $ids = $customers->pluck('id');

        // Agregados (sem N+1)
        $snap = CrmAccountHealthSnapshot::whereIn('customer_id', $ids)->orderByDesc('id')->get()->groupBy('customer_id')->map->first();
        $won = DB::table('crm_opportunities')->whereIn('customer_id', $ids)->where('status', 'ganho')->whereNull('deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('crm_opportunities.company_id', $cid))
            ->selectRaw('customer_id, SUM(valor) v')->groupBy('customer_id')->pluck('v', 'customer_id');
        $oppUlt = DB::table('crm_opportunities')->whereIn('customer_id', $ids)->whereNull('deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('crm_opportunities.company_id', $cid))
            ->selectRaw('customer_id, MAX(ultima_interacao_at) u')->groupBy('customer_id')->pluck('u', 'customer_id');
        $renov = DB::table('crm_opportunities')->whereIn('customer_id', $ids)->where('tipo', 'renovacao')->where('status', 'aberto')->whereNull('deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('crm_opportunities.company_id', $cid))
            ->selectRaw('customer_id, COUNT(*) c')->groupBy('customer_id')->pluck('c', 'customer_id');
        $fups = DB::table('crm_tasks as t')->join('crm_opportunities as o', 'o.id', '=', 't.opportunity_id')
            ->whereIn('o.customer_id', $ids)->whereNull('t.concluida_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('t.company_id', $cid))
            ->selectRaw('o.customer_id, COUNT(*) c')->groupBy('o.customer_id')->pluck('c', 'customer_id');
        // Projetos em risco (proxy sem Cronograma: consumo > 90% do contratado)
        $projRisk = DB::table('projects as p')->whereIn('p.customer_id', $ids)->whereNull('p.deleted_at')->where('p.sold_hours', '>', 0)
            ->leftJoinSub(
                DB::table('timesheets')->whereNull('deleted_at')->whereIn('status', ['approved', 'pending'])->selectRaw('project_id, SUM(effort_minutes) m')->groupBy('project_id'),
                'ts', 'ts.project_id', '=', 'p.id'
            )->selectRaw('p.customer_id, COUNT(*) FILTER (WHERE COALESCE(ts.m,0)/60.0 > p.sold_hours*0.9) risco')
            ->groupBy('p.customer_id')->pluck('risco', 'customer_id');

        $hoje = now()->startOfDay();
        if ($request->filled('saude')) $customers = $customers->filter(fn ($c) => ($snap[$c->id]->status ?? null) === $request->saude);

        $linhas = $customers->map(function ($c) use ($snap, $won, $oppUlt, $renov, $fups, $projRisk, $hoje) {
            $ult = collect([$c->crmProfile?->ultima_interacao_at, ($oppUlt[$c->id] ?? null) ? Carbon::parse($oppUlt[$c->id]) : null])->filter()->max();
            // Consistente com /relacionamento: sem interação registrada → conta a partir do cadastro.
            $base = $ult ?: $c->created_at;
            $dias = $base ? (int) Carbon::parse($base)->diffInDays($hoje) : null;
            return [
                'customer_id' => $c->id, 'name' => $c->name, 'crm_status' => $c->crm_status,
                'segmento' => $c->crmProfile?->segment, 'regiao' => $c->crmProfile?->region, 'executivo' => $c->executive?->name,
                'saude' => $snap[$c->id]->status ?? null,
                'receita' => round((float) ($won[$c->id] ?? 0), 2),
                'margem' => $snap[$c->id]?->margem !== null ? (float) $snap[$c->id]->margem : null,
                'renovacoes_abertas' => (int) ($renov[$c->id] ?? 0),
                'followups_pendentes' => (int) ($fups[$c->id] ?? 0),
                'projetos_risco' => (int) ($projRisk[$c->id] ?? 0),
                'dias_sem_interacao' => $dias,
            ];
        })->values();

        return response()->json(['data' => [
            'executivo_id' => $execId,
            'pode_ver_todos' => $podeVerTodos,
            'executivos' => $podeVerTodos ? User::where('is_crm_responsavel', true)->orderBy('name')->get(['id', 'name']) : [],
            'resumo' => [
                'clientes'              => $linhas->count(),
                'receita_total'         => round($linhas->sum('receita'), 2),
                'margem_total'          => round($linhas->sum('margem'), 2),
                'renovacoes_abertas'    => $linhas->sum('renovacoes_abertas'),
                'followups_pendentes'   => $linhas->sum('followups_pendentes'),
                'projetos_risco'        => $linhas->sum('projetos_risco'),
                'clientes_criticos'     => $linhas->where('saude', 'critico')->count(),
                'clientes_sem_interacao' => $linhas->filter(fn ($l) => ($l['dias_sem_interacao'] ?? 0) >= 30)->count(),
            ],
            'clientes' => $linhas->sortByDesc(fn ($l) => $l['saude'] === 'critico' ? 2 : ($l['saude'] === 'atencao' ? 1 : 0))->values(),
        ]]);
    }
}
