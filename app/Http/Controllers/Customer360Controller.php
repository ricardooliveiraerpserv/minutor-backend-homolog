<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\CrmCustomerEvent;
use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityEvent;
use App\Models\CrmProposal;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Timesheet;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Visão 360° da Empresa — FASE A (Ficha da Empresa). Lente única sobre a EMPRESA ÚNICA
 * (customers): cabeçalho + resumo executivo + aba CRM + timeline única. Carregamento por
 * seção (?sections=). Reusa modelos/queries existentes; NÃO duplica dados. Financeiro
 * detalhado / Cronograma / Atividades ficam como PONTO DE INTEGRAÇÃO (Fases B/C).
 */
class Customer360Controller extends Controller
{
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $requested = collect(explode(',', $request->query('sections', 'header,resumo,crm,saude,adm,serv,timeline')))
            ->map(fn ($s) => trim($s))->filter();
        $fresh = $request->boolean('fresh');
        $user = $request->user();

        // F2 — OWNERSHIP (backend): bloqueia acesso a empresa fora do escopo do perfil.
        abort_unless($this->canAccess($user, $customer), 403, 'Sem acesso a esta empresa.');

        // F1/F3 — contexto de permissões (o dado NÃO é retornado, não só escondido).
        $perms = $user ? PermissionService::for($user) : [];
        $can = fn (string $p) => in_array('*', $perms, true) || in_array($p, $perms, true);
        $ctx = [
            'comercial'  => $can('crm.view'),          // pipeline/oportunidades/perdas/forecast
            'financeiro' => $can('contracts.view'),    // adm + financeiro (receita/margem/custo)
            'serv'       => $can('projects.view'),      // projetos/apontamentos
            'despesas'   => $can('expenses.view_all'),  // valores de despesas
        ];
        // Assinatura do papel no cache key — evita servir dado de um perfil para outro.
        $sig = ($ctx['comercial'] ? 'c' : '-') . ($ctx['financeiro'] ? 'f' : '-') . ($ctx['serv'] ? 's' : '-') . ($ctx['despesas'] ? 'd' : '-');

        $allowed = collect(['header', 'resumo', 'timeline']);
        if ($ctx['comercial'])  $allowed->push('crm', 'saude');
        if ($ctx['financeiro']) $allowed->push('adm', 'financeiro');
        if ($ctx['serv'])       $allowed->push('serv');

        $sections = $requested->intersect($allowed);

        $data = [];
        foreach ($sections as $s) {
            if ($s === 'financeiro') {
                $comp = $request->query('competencia') ?: now()->format('Y-m');
                $data['financeiro'] = $this->cached("c360:{$customer->id}:{$sig}:fin:{$comp}", 600, $fresh, fn () => $this->financeiro($customer, $comp));
                continue;
            }
            $data[$s] = $this->cached("c360:{$customer->id}:{$sig}:{$s}", 90, $fresh, fn () => $this->{$s}($customer, $ctx));
        }

        return response()->json(['data' => $data, 'permissoes' => [
            'comercial' => $ctx['comercial'], 'adm' => $ctx['financeiro'], 'serv' => $ctx['serv'], 'despesas' => $ctx['despesas'],
        ]]);
    }

    /** Cache curto por seção (bypass com ?fresh=1). */
    private function cached(string $key, int $ttl, bool $fresh, callable $cb): array
    {
        if ($fresh) Cache::forget($key);
        return Cache::remember($key, now()->addSeconds($ttl), $cb);
    }

    /**
     * F2 — Ownership por perfil (validação no BACKEND, nunca no front).
     * admin/administrativo: total · coordenador: conforme permissões (customers.view amplo)
     * consultor: clientes com projeto/apontamento seu · cliente: só a própria empresa
     * parceiro: só empresas com apontamento de consultor do seu parceiro.
     */
    private function canAccess(?\App\Models\User $u, Customer $c): bool
    {
        if (!$u) return false;
        if ($u->isAdmin() || $u->type === 'administrativo' || $u->type === 'coordenador') return true;

        if ($u->type === 'cliente') {
            return (int) $u->customer_id === (int) $c->id;
        }
        if ($u->type === 'consultor') {
            $emProjeto = Project::where('customer_id', $c->id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereHas('consultants', fn ($s) => $s->where('users.id', $u->id))
                    ->orWhereHas('coordinators', fn ($s) => $s->where('users.id', $u->id)))->exists();
            $temApont = Timesheet::where('user_id', $u->id)->whereNull('deleted_at')
                ->whereHas('project', fn ($q) => $q->where('customer_id', $c->id))->exists();
            return $emProjeto || $temApont;
        }
        if ($u->type === 'parceiro_admin') {
            if (!$u->partner_id) return false;
            $partnerUserIds = \App\Models\User::where('partner_id', $u->partner_id)->pluck('id');
            return Timesheet::whereIn('user_id', $partnerUserIds)->whereNull('deleted_at')
                ->whereHas('project', fn ($q) => $q->where('customer_id', $c->id))->exists();
        }
        return false;
    }

    private function header(Customer $customer, array $ctx = []): array
    {
        $comercial = $ctx['comercial'] ?? false;
        $customer->loadMissing('crmProfile.leadSource', 'executive:id,name');
        $p = $customer->crmProfile;

        $base = [
            'customer_id'     => $customer->id,
            'name'            => $customer->name,
            'crm_status'      => $customer->crm_status,
            'segment'         => $p?->segment,
            'executivo_conta' => $customer->executive?->name,
            'data_cadastro'   => optional($customer->created_at)->toDateString(),
        ];
        // Campos comerciais só para quem tem crm.view (F1).
        if (!$comercial) {
            return $base + ['responsavel_comercial' => null, 'ultima_interacao_at' => null, 'proxima_acao' => null];
        }
        $ultimaOpp = CrmOpportunity::where('customer_id', $customer->id)->latest('id')->with('responsavel:id,name')->first();
        $ultimaInteracao = collect([
            CrmOpportunity::where('customer_id', $customer->id)->max('ultima_interacao_at'),
            $p?->ultima_interacao_at,
        ])->filter()->max();

        return $base + [
            'responsavel_comercial' => $ultimaOpp?->responsavel?->name,
            'ultima_interacao_at'   => $ultimaInteracao,
            'proxima_acao'          => $this->proximaAcao($customer),  // F6 — unificada
        ];
    }

    /**
     * F6 — Próxima Ação da Empresa (unificada): a ação FUTURA mais próxima entre
     * oportunidades abertas, CRM tasks (follow-ups) e a próxima ação do lead/prospect.
     */
    private function proximaAcao(Customer $customer): ?array
    {
        $hoje = today();
        $oppIds = CrmOpportunity::where('customer_id', $customer->id)->pluck('id');
        $cands = collect();

        CrmOpportunity::where('customer_id', $customer->id)->where('status', 'aberto')
            ->whereNotNull('proxima_acao_at')->whereDate('proxima_acao_at', '>=', $hoje)
            ->with('responsavel:id,name')->get()
            ->each(fn ($o) => $cands->push(['data' => $o->proxima_acao_at, 'descricao' => $o->proxima_acao,
                'origem' => 'Oportunidade: ' . $o->title, 'responsavel' => $o->responsavel?->name]));

        \App\Models\CrmTask::whereIn('opportunity_id', $oppIds)->whereNull('concluida_at')
            ->whereNotNull('data')->whereDate('data', '>=', $hoje)->with('responsavel:id,name')->get()
            ->each(fn ($t) => $cands->push(['data' => $t->data, 'descricao' => $t->titulo ?? $t->tipo,
                'origem' => 'Follow-up / Tarefa', 'responsavel' => $t->responsavel?->name]));

        $p = $customer->crmProfile;
        if ($p?->proxima_acao_at && $p->proxima_acao_at->gte($hoje)) {
            $cands->push(['data' => $p->proxima_acao_at, 'descricao' => $p->proxima_acao,
                'origem' => 'Lead / Prospect', 'responsavel' => $customer->executive?->name]);
        }

        $next = $cands->sortBy(fn ($a) => (string) $a['data'])->first();
        return $next ? [
            'descricao'   => $next['descricao'],
            'origem'      => $next['origem'],
            'responsavel' => $next['responsavel'],
            'data'        => Carbon::parse($next['data'])->toDateString(),
        ] : null;
    }

    private function resumo(Customer $customer, array $ctx = []): array
    {
        $projetos = Project::where('customer_id', $customer->id)->whereNull('deleted_at');
        $projectIds = (clone $projetos)->pluck('id');
        $horasConsumidas = (float) Timesheet::whereIn('project_id', $projectIds)
            ->whereNull('deleted_at')->whereIn('status', ['approved', 'pending'])
            ->sum('effort_minutes') / 60;

        // KPIs operacionais — disponíveis a quem tem acesso à empresa.
        $out = [
            'contratos_ativos'  => (int) Contract::where('customer_id', $customer->id)->where('status', 'ativo')->count(),
            'projetos_ativos'   => (int) (clone $projetos)->count(),
            'horas_contratadas' => round((float) (clone $projetos)->sum('sold_hours'), 2),
            'horas_consumidas'  => round($horasConsumidas, 2),
        ];
        // F1 — pipeline/valor só comercial; receita/rentabilidade só financeiro (lazy).
        if ($ctx['comercial'] ?? false) {
            $opp = CrmOpportunity::where('customer_id', $customer->id)->where('status', 'aberto');
            $out['oportunidades_abertas'] = (int) (clone $opp)->count();
            $out['valor_pipeline'] = round((float) (clone $opp)->sum('valor'), 2);
        }
        if ($ctx['financeiro'] ?? false) {
            $out['receita_total'] = null;
            $out['rentabilidade'] = null;
            $out['financeiro_pendente'] = true;
        }
        return $out;
    }

    private function crm(Customer $customer, array $ctx = []): array
    {
        $oppIds = CrmOpportunity::where('customer_id', $customer->id)->pluck('id');
        $oppTotal = $oppIds->count();
        $opps = CrmOpportunity::where('customer_id', $customer->id)
            ->with(['stage:id,name', 'responsavel:id,name', 'lossReason:id,name'])
            ->orderByDesc('id')->limit(50)->get();   // F4 — top 50 + total
        $p = $customer->crmProfile;

        $produtos = DB::table('crm_opportunity_products as op')
            ->join('crm_products as pr', 'pr.id', '=', 'op.crm_product_id')
            ->whereIn('op.opportunity_id', $oppIds)
            ->select('pr.name', 'pr.categoria')->distinct()->get()
            ->map(fn ($r) => ['name' => $r->name, 'categoria' => $r->categoria]);

        return [
            'lead_created_at' => $p?->lead_created_at,
            'qualified_at'    => $p?->qualified_at,
            'oportunidades'   => $opps->map(fn ($o) => [
                'id' => $o->id, 'title' => $o->title, 'status' => $o->status,
                'stage' => $o->stage?->name, 'valor' => (float) $o->valor,
                'responsavel' => $o->responsavel?->name, 'proxima_acao_at' => optional($o->proxima_acao_at)->toDateString(),
            ])->values(),
            'oportunidades_total' => $oppTotal,
            'propostas_count' => (int) CrmProposal::whereIn('opportunity_id', $oppIds)->count(),
            'conversoes_count' => (int) $opps->whereNotNull('contract_id')->count(),
            'perdas'          => $opps->where('status', 'perdido')->map(fn ($o) => [
                'title' => $o->title, 'motivo' => $o->lossReason?->name ?? $o->motivo,
            ])->values(),
            'produtos_interesse' => $produtos,
        ];
    }

    /** Fase B — Administrativo: contratos + banco de horas (leve, sem Keruak). */
    private function adm(Customer $customer, array $ctx = []): array
    {
        $BH = ['banco_horas_fixo', 'banco_horas_mensal'];
        $contratosTotal = Contract::where('customer_id', $customer->id)->count();
        $contratos = Contract::where('customer_id', $customer->id)
            ->with('contractType:id,name', 'executivoConta:id,name', 'vendedor:id,name')
            ->orderByDesc('id')->limit(50)->get()   // F4 — top 50 + total
            ->map(fn ($c) => [
                'id'             => $c->id,
                'projeto'        => $c->project_name ?: ('Contrato #' . $c->id),
                'tipo'           => $c->contractType?->name ?? ($c->categoria ?: '—'),
                'tipo_faturamento' => $c->tipo_faturamento,
                'status'         => $c->status,
                'valor'          => (float) ($c->valor_projeto ?? $c->valor_hora ?? 0),
                'horas_contratadas' => (float) ($c->horas_contratadas ?? 0),
                'data_vencimento' => optional($c->data_vencimento)->toDateString(),
                'executivo_conta' => $c->executivoConta?->name,
                'vendedor'       => $c->vendedor?->name,
                'is_banco_horas' => in_array($c->tipo_faturamento, $BH, true),
            ]);

        $projectIds = Project::where('customer_id', $customer->id)->whereNull('deleted_at')->pluck('id');
        $contratadas = (float) Project::whereIn('id', $projectIds)->sum('sold_hours');
        $consumidas = (float) Timesheet::whereIn('project_id', $projectIds)
            ->whereNull('deleted_at')->whereIn('status', ['approved', 'pending'])->sum('effort_minutes') / 60;

        return [
            'contratos'      => $contratos,
            'contratos_total' => $contratosTotal,
            'contratos_ativos' => (int) Contract::where('customer_id', $customer->id)->where('status', 'ativo')->count(),
            'banco_horas'    => [
                'contratadas' => round($contratadas, 2),
                'consumidas'  => round($consumidas, 2),
                'saldo'       => round($contratadas - $consumidas, 2),
            ],
        ];
    }

    /**
     * Fase B — Financeiro (LAZY): receita/custo/margem do cliente numa competência,
     * REUSANDO o cruzamento Rentabilidade × Keruak já existente. Keruak é HTTP/HTML, então
     * é seção sob demanda e degrada com elegância se indisponível.
     */
    private function financeiro(Customer $customer, ?string $competencia): array
    {
        $competencia = $competencia ?: now()->format('Y-m');
        try {
            $resp = app(RelatorioRentabilidadeController::class)->clientes(new Request(), $competencia);
            $rows = $resp->getData(true)['data']['rows'] ?? [];
            $row = collect($rows)->firstWhere('customer_id', $customer->id);
            if (!$row) {
                return ['competencia' => $competencia, 'sem_dados' => true,
                    'receita' => 0, 'recebido' => 0, 'custo' => 0, 'margem' => 0, 'margem_pct' => null, 'horas' => 0];
            }
            return [
                'competencia' => $competencia,
                'receita'     => (float) ($row['receita'] ?? 0),     // faturado (horas × valor)
                'recebido'    => (float) ($row['recebido'] ?? 0),    // recebido (Keruak)
                'custo'       => (float) ($row['custo'] ?? 0),
                'margem'      => (float) ($row['margem'] ?? 0),
                'margem_pct'  => $row['margem_pct'] ?? null,
                'horas'       => (float) ($row['horas'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['competencia' => $competencia, 'financeiro_indisponivel' => true,
                'erro' => 'Não foi possível calcular agora (Rentabilidade/Keruak indisponível).'];
        }
    }

    /** Roadmap Fase 1 — Saúde da Conta (badge + motivos + histórico). Gated comercial. */
    private function saude(Customer $customer, array $ctx = []): array
    {
        // Margem só p/ quem tem financeiro (último snapshot; não chama Keruak ao vivo).
        $margem = null;
        if ($ctx['financeiro'] ?? false) {
            $margem = optional(\App\Models\CrmAccountHealthSnapshot::where('customer_id', $customer->id)
                ->whereNotNull('margem')->latest('id')->first())->margem;
            $margem = $margem !== null ? (float) $margem : null;
        }
        $r = app(\App\Services\AccountHealthService::class)->compute($customer, $margem);
        $hist = \App\Models\CrmAccountHealthSnapshot::where('customer_id', $customer->id)
            ->orderByDesc('id')->limit(12)->get(['status', 'score', 'created_at'])
            ->map(fn ($s) => ['status' => $s->status, 'score' => $s->score, 'when' => optional($s->created_at)->toDateString()])
            ->reverse()->values();
        return [
            'score' => $r['score'], 'status' => $r['status'], 'critico_imediato' => $r['critico_imediato'],
            'motivos' => collect($r['motivos'])->map(fn ($m) => ['texto' => $m['texto'], 'peso' => $m['peso'] ?? 0, 'critico' => $m['critico'] ?? false])->values(),
            'historico' => $hist,
        ];
    }

    /** Fase C — Serviços: projetos + apontamentos + despesas (Cronograma/Atividades = integração futura). */
    private function serv(Customer $customer, array $ctx = []): array
    {
        $ATIVOS = ['started', 'liberado_para_testes', 'paused', 'awaiting_start'];
        $projetosTotal = Project::where('customer_id', $customer->id)->whereNull('deleted_at')->count();
        $allProjectIds = Project::where('customer_id', $customer->id)->whereNull('deleted_at')->pluck('id');
        $projects = Project::where('customer_id', $customer->id)->whereNull('deleted_at')->orderByDesc('id')->limit(50)->get(); // F4
        $projectIds = $projects->pluck('id');

        $consumo = Timesheet::whereIn('project_id', $projectIds)->whereNull('deleted_at')
            ->whereIn('status', ['approved', 'pending'])
            ->selectRaw('project_id, SUM(effort_minutes) as min')->groupBy('project_id')->pluck('min', 'project_id');

        $projetos = $projects->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'status' => $p->status,
            'ativo' => in_array($p->status, $ATIVOS, true),
            'sold_hours' => (float) $p->sold_hours,
            'horas_consumidas' => round((float) ($consumo[$p->id] ?? 0) / 60, 2),
        ]);

        // Agregados sobre TODOS os projetos do cliente (não só os 50 exibidos).
        $tsBase = Timesheet::whereIn('project_id', $allProjectIds)->whereNull('deleted_at')->whereIn('status', ['approved', 'pending']);
        $recentes = (clone $tsBase)->with('user:id,name', 'project:id,name')->orderByDesc('date')->limit(8)->get()
            ->map(fn ($t) => [
                'data'      => $t->date ? Carbon::parse($t->date)->toDateString() : null,
                'consultor' => $t->user?->name,
                'projeto'   => $t->project?->name,
                'horas'     => round((float) $t->effort_minutes / 60, 2),
                'obs'       => $t->observation,
            ]);

        $out = [
            'projetos'         => $projetos->values(),
            'projetos_total'   => $projetosTotal,
            'projetos_ativos'  => (int) Project::where('customer_id', $customer->id)->whereNull('deleted_at')->whereIn('status', $ATIVOS)->count(),
            'apontamentos'     => [
                'total_horas' => round((float) (clone $tsBase)->sum('effort_minutes') / 60, 2),
                'lancamentos' => (int) (clone $tsBase)->count(),
                'recentes'    => $recentes->values(),
            ],
            // Pontos de integração (Fase futura — não implementados):
            'cronograma_pendente' => true,
            'atividades_pendente' => true,
        ];

        // F3 — valores de despesas só p/ quem tem expenses.view_all (admin/administrativo/coordenador).
        if ($ctx['despesas'] ?? false) {
            $despBase = Expense::whereIn('project_id', $allProjectIds);
            $despTotal = (float) (clone $despBase)->sum('amount');
            $despPagas = (float) (clone $despBase)->where('is_paid', true)->sum('amount');
            $out['despesas'] = [
                'total'     => round($despTotal, 2),
                'pagas'     => round($despPagas, 2),
                'pendentes' => round($despTotal - $despPagas, 2),
                'lancamentos' => (int) (clone $despBase)->count(),
            ];
        }

        return $out;
    }

    /** Timeline única: funde eventos de empresa (lead) + oportunidade + contrato. */
    private function timeline(Customer $customer, array $ctx = []): array
    {
        $comercial = $ctx['comercial'] ?? false;
        $contractIds = Contract::where('customer_id', $customer->id)->pluck('id');
        $merged = collect();

        // Eventos comerciais (lead/oportunidade) só p/ perfis comerciais (F1).
        if ($comercial) {
            $oppIds = CrmOpportunity::where('customer_id', $customer->id)->pluck('id');
            $merged = $merged->concat(
                CrmCustomerEvent::where('customer_id', $customer->id)->orderByDesc('created_at')->limit(50)->get()
                    ->map(fn ($e) => ['when' => $e->created_at, 'source' => 'lead', 'type' => $e->event_type, 'label' => $e->label])
            )->concat(
                CrmOpportunityEvent::whereIn('opportunity_id', $oppIds)->orderByDesc('created_at')->limit(50)->get()
                    ->map(fn ($e) => ['when' => $e->created_at, 'source' => 'crm', 'type' => $e->event_type, 'label' => $e->to_value])
            )->concat(
                // Fase 7 — follow-ups (relacionamento) na timeline.
                \App\Models\CrmTask::where('customer_id', $customer->id)->orderByDesc('id')->limit(50)->get()
                    ->map(fn ($t) => ['when' => $t->concluida_at ?? $t->data ?? $t->created_at, 'source' => 'followup',
                        'type' => $t->categoria ?: $t->tipo, 'label' => $t->titulo])
            );
        }
        // Eventos de contrato — operacionais (visíveis a quem acessa a empresa).
        $merged = $merged->concat(
            ContractEvent::whereIn('contract_id', $contractIds)->orderByDesc('created_at')->limit(50)->get()
                ->map(fn ($e) => ['when' => $e->created_at, 'source' => 'contrato', 'type' => $e->event_type, 'label' => $e->to_value ?? $e->field])
        );

        $ordenado = $merged->sortByDesc('when')->values();
        return [
            'eventos' => $ordenado->take(50)->all(),   // F4 — top 50 + total
            'total'   => $ordenado->count(),
        ];
    }
}
