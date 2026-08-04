<?php

namespace App\Http\Controllers;

use App\Models\CrmOpportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** CRM — métricas do dashboard comercial. */
class CrmDashboardController extends Controller
{
    use \App\Http\Traits\FiltersByActiveCompany;

    public function summary(): JsonResponse
    {
        $abertas  = CrmOpportunity::where('status', 'aberto');
        $totAbertas = (clone $abertas)->count();
        $valAbertas = (float) (clone $abertas)->sum('valor');
        $semProxima = (clone $abertas)->whereNull('proxima_acao_at')->count();

        // Item 1 — saúde do acompanhamento (oportunidades abertas)
        $proximaVencida = (clone $abertas)->whereNotNull('proxima_acao_at')->whereDate('proxima_acao_at', '<', now())->count();
        $semInteracao = fn ($dias) => (clone $abertas)
            ->where(fn ($q) => $q->whereNull('ultima_interacao_at')->orWhere('ultima_interacao_at', '<', now()->subDays($dias)))->count();
        $sem7 = $semInteracao(7); $sem15 = $semInteracao(15); $sem30 = $semInteracao(30);

        // Empresas por status (EMPRESA ÚNICA — tudo vem de customers.crm_status; sem tabela paralela)
        $statusRaw = DB::table('customers')->whereNull('deleted_at')
            ->selectRaw('crm_status, COUNT(*) as qtd')->groupBy('crm_status')->pluck('qtd', 'crm_status');
        $empresasPorStatus = [];
        foreach (\App\Models\Customer::CRM_STATUSES as $st) {
            $empresasPorStatus[$st] = (int) ($statusRaw[$st] ?? 0);
        }

        $ganhas  = CrmOpportunity::where('status', 'ganho');
        $perdidas = CrmOpportunity::where('status', 'perdido')->count();
        $totGanhas = (clone $ganhas)->count();
        $valGanhas = (float) (clone $ganhas)->sum('valor');

        // Pipeline por executivo (abertas) + receita ganha por vendedor
        $porResponsavel = CrmOpportunity::select('responsavel_id',
                DB::raw("COUNT(*) FILTER (WHERE status='aberto') as abertas"),
                DB::raw("COALESCE(SUM(valor) FILTER (WHERE status='aberto'),0) as valor_aberto"),
                DB::raw("COALESCE(SUM(valor) FILTER (WHERE status='ganho'),0) as valor_ganho"))
            ->groupBy('responsavel_id')->with('responsavel:id,name')->get()
            ->map(fn ($r) => ['responsavel' => $r->responsavel?->name ?? '—', 'abertas' => (int) $r->abertas, 'valor_aberto' => (float) $r->valor_aberto, 'valor_ganho' => (float) $r->valor_ganho]);

        // Receita ganha por segmento (perfil firmográfico da empresa)
        $porSegmento = DB::table('crm_opportunities as o')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('customer_crm_profiles as p', 'p.customer_id', '=', 'c.id')
            ->where('o.status', 'ganho')->whereNull('o.deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw("COALESCE(p.segment, 'Sem segmento') as segment, SUM(o.valor) as valor")
            ->groupBy('segment')->orderByDesc('valor')->get()
            ->map(fn ($r) => ['segment' => $r->segment, 'valor' => (float) $r->valor]);

        // Receita ganha por produto
        $porProduto = DB::table('crm_opportunity_products as op')
            ->join('crm_opportunities as o', 'o.id', '=', 'op.opportunity_id')
            ->join('crm_products as pr', 'pr.id', '=', 'op.crm_product_id')
            ->where('o.status', 'ganho')->whereNull('o.deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw('pr.name as produto, SUM(op.valor) as valor')
            ->groupBy('pr.name')->orderByDesc('valor')->get()
            ->map(fn ($r) => ['produto' => $r->produto, 'valor' => (float) $r->valor]);

        // Receita ganha por categoria de produto
        $porCategoria = DB::table('crm_opportunity_products as op')
            ->join('crm_opportunities as o', 'o.id', '=', 'op.opportunity_id')
            ->join('crm_products as pr', 'pr.id', '=', 'op.crm_product_id')
            ->where('o.status', 'ganho')->whereNull('o.deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw("COALESCE(op.categoria, pr.categoria, 'Sem categoria') as categoria, SUM(op.quantidade * op.valor) as valor")
            ->groupBy('categoria')->orderByDesc('valor')->get()
            ->map(fn ($r) => ['categoria' => $r->categoria, 'valor' => (float) $r->valor]);

        // Item 2 — perdas por motivo / executivo / produto
        $perdasPorMotivo = DB::table('crm_opportunities as o')
            ->leftJoin('crm_loss_reasons as r', 'r.id', '=', 'o.loss_reason_id')
            ->where('o.status', 'perdido')->whereNull('o.deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw("COALESCE(r.name, 'Sem motivo') as motivo, COUNT(*) as qtd, COALESCE(SUM(o.valor),0) as valor")
            ->groupBy('r.name')->orderByDesc('qtd')->get()
            ->map(fn ($r) => ['motivo' => $r->motivo, 'qtd' => (int) $r->qtd, 'valor' => (float) $r->valor]);

        $perdasPorExecutivo = DB::table('crm_opportunities as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.responsavel_id')
            ->where('o.status', 'perdido')->whereNull('o.deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw("COALESCE(u.name, '—') as executivo, COUNT(*) as qtd")
            ->groupBy('u.name')->orderByDesc('qtd')->get()
            ->map(fn ($r) => ['executivo' => $r->executivo, 'qtd' => (int) $r->qtd]);

        $perdasPorProduto = DB::table('crm_opportunity_products as op')
            ->join('crm_opportunities as o', 'o.id', '=', 'op.opportunity_id')
            ->join('crm_products as pr', 'pr.id', '=', 'op.crm_product_id')
            ->where('o.status', 'perdido')->whereNull('o.deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw('pr.name as produto, COUNT(DISTINCT o.id) as qtd')
            ->groupBy('pr.name')->orderByDesc('qtd')->get()
            ->map(fn ($r) => ['produto' => $r->produto, 'qtd' => (int) $r->qtd]);

        // Tempo médio até fechamento (dias) das ganhas
        $tempoMedio = (float) (CrmOpportunity::where('status', 'ganho')->whereNotNull('fechamento_at')->whereNotNull('data_abertura')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (fechamento_at - data_abertura)) / 86400) as d")->value('d') ?? 0);

        // Forecast por etapa (abertas)
        $forecast = DB::table('crm_opportunities as o')
            ->join('crm_pipeline_stages as s', 's.id', '=', 'o.stage_id')
            ->where('o.status', 'aberto')->whereNull('o.deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw('s.name as etapa, COUNT(*) as qtd, SUM(o.valor) as valor')
            ->groupBy('s.name')->get()
            ->map(fn ($r) => ['etapa' => $r->etapa, 'qtd' => (int) $r->qtd, 'valor' => (float) $r->valor]);

        return response()->json(['data' => [
            'abertas_count' => $totAbertas, 'abertas_valor' => round($valAbertas, 2),
            'ganhas_count' => $totGanhas, 'ganhas_valor' => round($valGanhas, 2),
            'perdidas_count' => $perdidas,
            'empresas_por_status' => $empresasPorStatus,
            'sem_proxima_acao' => $semProxima,
            'proxima_acao_vencida' => $proximaVencida,
            'sem_interacao_7' => $sem7, 'sem_interacao_15' => $sem15, 'sem_interacao_30' => $sem30,
            'perdas_por_motivo' => $perdasPorMotivo,
            'perdas_por_executivo' => $perdasPorExecutivo,
            'perdas_por_produto' => $perdasPorProduto,
            'tempo_medio_fechamento_dias' => round($tempoMedio, 1),
            'por_responsavel' => $porResponsavel,
            'por_segmento' => $porSegmento,
            'por_produto' => $porProduto,
            'por_categoria' => $porCategoria,
            'forecast_por_etapa' => $forecast,
        ]]);
    }

    /**
     * CRM Item 4 — funil de conversão completo: contagens por estágio do ciclo,
     * taxas de conversão entre estágios e tempos médios. Sem schema novo (usa timestamps).
     */
    public function funil(): JsonResponse
    {
        return response()->json(['data' => $this->funilData()]);
    }

    /** Dados do funil de conversão (reutilizado pelo Forecast). */
    private function funilData(): array
    {
        // Contagens dos estágios do ciclo comercial
        $leadsCriados = (int) DB::table('customer_crm_profiles')->whereNotNull('lead_created_at')->count();
        $prospects    = (int) DB::table('customer_crm_profiles')->whereNotNull('qualified_at')->count();
        $oppsAbertas  = (int) CrmOpportunity::where('status', 'aberto')->count();
        $oppsTotal    = (int) CrmOpportunity::count();
        $propostas    = (int) DB::table('crm_proposals')->whereNull('deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('crm_proposals.company_id', $cid))->count();
        $contratos    = (int) CrmOpportunity::whereNotNull('contract_id')->count();

        // Clientes que já tiveram oportunidade (base para Prospect→Oportunidade)
        $prospectsComOpp = (int) DB::table('crm_opportunities as o')
            ->join('customer_crm_profiles as p', 'p.customer_id', '=', 'o.customer_id')
            ->whereNull('o.deleted_at')->whereNotNull('p.qualified_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->distinct('o.customer_id')->count('o.customer_id');
        // Oportunidades que geraram proposta / que viraram contrato
        $oppsComProposta = (int) DB::table('crm_proposals')->whereNull('deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('crm_proposals.company_id', $cid))
            ->distinct('opportunity_id')->count('opportunity_id');
        $propostasComContrato = (int) DB::table('crm_proposals as pr')
            ->join('crm_opportunities as o', 'o.id', '=', 'pr.opportunity_id')
            ->whereNull('pr.deleted_at')->whereNotNull('o.contract_id')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('pr.company_id', $cid))->count();

        $pct = fn ($num, $den) => $den > 0 ? round($num / $den * 100, 1) : 0.0;

        // Tempos médios (dias)
        $diasLeadProspect = (float) (DB::table('customer_crm_profiles')
            ->whereNotNull('qualified_at')->whereNotNull('lead_created_at')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (qualified_at - lead_created_at))/86400) as d")->value('d') ?? 0);
        // Prospect→Oportunidade: 1ª oportunidade do cliente vs qualified_at
        $diasProspectOpp = (float) (DB::table('crm_opportunities as o')
            ->join('customer_crm_profiles as p', 'p.customer_id', '=', 'o.customer_id')
            ->whereNull('o.deleted_at')->whereNotNull('p.qualified_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (o.created_at - p.qualified_at))/86400) as d")->value('d') ?? 0);
        // Oportunidade→Contrato: contrato gerado vs criação da oportunidade
        $diasOppContrato = (float) (DB::table('crm_opportunities as o')
            ->join('contracts as c', 'c.id', '=', 'o.contract_id')
            ->whereNull('o.deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (c.created_at - o.created_at))/86400) as d")->value('d') ?? 0);

        // Valor por etapa (Lead/Prospect = potencial; Oport/Proposta/Contrato = valor real)
        $valLeads = (float) DB::table('customer_crm_profiles')->whereNotNull('lead_created_at')->sum('valor_potencial');
        $valProspects = (float) DB::table('customer_crm_profiles')->whereNotNull('qualified_at')->sum('valor_potencial');
        $valOpps = (float) CrmOpportunity::sum('valor');
        $valProp = (float) DB::table('crm_proposals')->whereNull('deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('crm_proposals.company_id', $cid))
            ->sum(DB::raw('valor - descontos'));
        $valContr = (float) CrmOpportunity::whereNotNull('contract_id')->sum('valor');

        return [
            'etapas' => [
                ['etapa' => 'Leads',         'qtd' => $leadsCriados, 'valor' => round($valLeads, 2), 'conversao' => null],
                ['etapa' => 'Prospects',     'qtd' => $prospects, 'valor' => round($valProspects, 2), 'conversao' => $pct($prospects, $leadsCriados)],
                ['etapa' => 'Oportunidades', 'qtd' => $oppsTotal, 'valor' => round($valOpps, 2), 'conversao' => $pct($prospectsComOpp, $prospects)],
                ['etapa' => 'Propostas',     'qtd' => $propostas, 'valor' => round($valProp, 2), 'conversao' => $pct($oppsComProposta, $oppsTotal)],
                ['etapa' => 'Contratos',     'qtd' => $contratos, 'valor' => round($valContr, 2), 'conversao' => $pct($propostasComContrato, $propostas)],
            ],
            'contagens' => [
                'leads_criados' => $leadsCriados, 'leads_qualificados' => $prospects,
                'oportunidades_abertas' => $oppsAbertas, 'oportunidades_total' => $oppsTotal,
                'propostas_emitidas' => $propostas, 'contratos_gerados' => $contratos,
            ],
            'conversoes' => [
                'lead_prospect'       => $pct($prospects, $leadsCriados),
                'prospect_oportunidade' => $pct($prospectsComOpp, $prospects),
                'oportunidade_proposta' => $pct($oppsComProposta, $oppsTotal),
                'proposta_contrato'   => $pct($propostasComContrato, $propostas),
            ],
            'tempo_medio_dias' => [
                'lead_prospect'       => round($diasLeadProspect, 1),
                'prospect_oportunidade' => round($diasProspectOpp, 1),
                'oportunidade_contrato' => round($diasOppContrato, 1),
            ],
        ];
    }

    /**
     * Roadmap Fase 6 — Origem REAL da receita: por origem cadastrada (crm_lead_sources),
     * cruza leads × oportunidades × contratos × receita. Tudo via customer_crm_profiles.lead_source_id
     * (empresa única). Sem schema novo.
     */
    public function origem(): JsonResponse
    {
        // Leads/qualificados por origem (perfil firmográfico).
        $leadsPorOrigem = DB::table('crm_lead_sources as s')
            ->leftJoin('customer_crm_profiles as p', 'p.lead_source_id', '=', 's.id')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('s.company_id', $cid))
            ->selectRaw("s.id, s.name,
                COUNT(p.customer_id) FILTER (WHERE p.lead_created_at IS NOT NULL) as leads,
                COUNT(p.customer_id) FILTER (WHERE p.qualified_at IS NOT NULL) as qualificados")
            ->groupBy('s.id', 's.name')->get()->keyBy('id');

        // Oportunidades / ganhas / contratos / receita / tempo de fechamento por origem.
        $opp = DB::table('crm_opportunities as o')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->join('customer_crm_profiles as p', 'p.customer_id', '=', 'c.id')
            ->whereNull('o.deleted_at')->whereNotNull('p.lead_source_id')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw("p.lead_source_id,
                COUNT(*) as oportunidades,
                COUNT(*) FILTER (WHERE o.status='ganho') as ganhas,
                COUNT(*) FILTER (WHERE o.contract_id IS NOT NULL) as contratos,
                COALESCE(SUM(o.valor) FILTER (WHERE o.status='ganho'),0) as receita,
                AVG(EXTRACT(EPOCH FROM (o.fechamento_at - o.data_abertura))/86400) FILTER (WHERE o.status='ganho' AND o.fechamento_at IS NOT NULL) as tempo_dias")
            ->groupBy('p.lead_source_id')->get()->keyBy('lead_source_id');

        $linhas = $leadsPorOrigem->map(function ($l) use ($opp) {
            $o = $opp[$l->id] ?? null;
            $leads = (int) $l->leads;
            $contratos = (int) ($o->contratos ?? 0);
            $ganhas = (int) ($o->ganhas ?? 0);
            $receita = (float) ($o->receita ?? 0);
            return [
                'origem'        => $l->name,
                'leads'         => $leads,
                'qualificados'  => (int) $l->qualificados,
                'oportunidades' => (int) ($o->oportunidades ?? 0),
                'ganhas'        => $ganhas,
                'contratos'     => $contratos,
                'receita'       => round($receita, 2),
                'ticket_medio'  => $ganhas > 0 ? round($receita / $ganhas, 2) : 0.0,
                'conversao_lead_contrato' => $leads > 0 ? round($contratos / $leads * 100, 1) : 0.0,
                'tempo_medio_fechamento_dias' => round((float) ($o->tempo_dias ?? 0), 1),
            ];
        })->sortByDesc('receita')->values();

        return response()->json(['data' => $linhas]);
    }

    /**
     * Roadmap Fase 3 — Relacionamento comercial: clientes sem interação (15/30/60/90d) e
     * sem próxima ação. Interação = max(profile.ultima_interacao_at, opp.ultima_interacao_at);
     * fallback created_at (cliente nunca contatado conta como esquecido a partir do cadastro).
     */
    public function relacionamento(Request $request): JsonResponse
    {
        $hoje = now()->startOfDay();
        $comercial = ['lead', 'prospect', 'cliente', 'em_renovacao'];

        $customers = \App\Models\Customer::whereNull('deleted_at')->whereIn('crm_status', $comercial)
            ->with(['crmProfile:customer_id,ultima_interacao_at,proxima_acao_at', 'executive:id,name'])
            ->when($request->filled('executivo_id'), fn ($q) => $q->where('executive_id', $request->executivo_id))
            ->get();

        // Agregados de oportunidade por cliente (1 query).
        $oppAgg = DB::table('crm_opportunities')->whereNull('deleted_at')
            ->whereIn('customer_id', $customers->pluck('id'))
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('crm_opportunities.company_id', $cid))
            ->selectRaw("customer_id,
                MAX(ultima_interacao_at) as ult,
                COUNT(*) FILTER (WHERE status='aberto' AND proxima_acao_at IS NOT NULL AND proxima_acao_at >= now()) as com_proxima")
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $linhas = $customers->map(function ($c) use ($oppAgg, $hoje) {
            $agg = $oppAgg[$c->id] ?? null;
            $ult = collect([$c->crmProfile?->ultima_interacao_at, $agg?->ult ? \Illuminate\Support\Carbon::parse($agg->ult) : null])->filter()->max();
            $base = $ult ?: $c->created_at;
            $dias = $base ? (int) \Illuminate\Support\Carbon::parse($base)->diffInDays($hoje) : null;
            $temProxima = ($agg && $agg->com_proxima > 0)
                || ($c->crmProfile?->proxima_acao_at && \Illuminate\Support\Carbon::parse($c->crmProfile->proxima_acao_at)->gte($hoje));
            return [
                'customer_id' => $c->id, 'name' => $c->name, 'crm_status' => $c->crm_status,
                'executivo' => $c->executive?->name,
                'ultima_interacao_at' => optional($ult)->toDateString(),
                'dias_sem_interacao' => $dias,
                'tem_proxima_acao' => (bool) $temProxima,
            ];
        });

        $semInter = fn ($d) => $linhas->filter(fn ($l) => $l['dias_sem_interacao'] !== null && $l['dias_sem_interacao'] >= $d)->count();

        // Filtros de lista
        $lista = $linhas;
        if ($request->boolean('sem_proxima')) $lista = $lista->filter(fn ($l) => !$l['tem_proxima_acao']);
        if ($request->filled('min_dias')) $lista = $lista->filter(fn ($l) => ($l['dias_sem_interacao'] ?? 0) >= (int) $request->min_dias);
        $lista = $lista->sortByDesc('dias_sem_interacao')->values();

        return response()->json(['data' => [
            'resumo' => [
                'sem_interacao_15' => $semInter(15), 'sem_interacao_30' => $semInter(30),
                'sem_interacao_60' => $semInter(60), 'sem_interacao_90' => $semInter(90),
                'sem_proxima_acao' => $linhas->where('tem_proxima_acao', false)->count(),
                'total_comercial'  => $linhas->count(),
            ],
            'clientes' => $lista,
        ]]);
    }

    /** CRM Item 5 — indicadores de renovação. */
    public function renovacoes(): JsonResponse
    {
        $hoje = now()->startOfDay();
        $limite = (clone $hoje)->addDays(90);

        $proximosVencimento = (int) \App\Models\Contract::whereNotNull('data_vencimento')
            ->whereNotNull('project_id')
            ->whereDate('data_vencimento', '>=', $hoje)->whereDate('data_vencimento', '<=', $limite)->count();

        // Funil de renovação: status das oportunidades de tipo renovação
        $base = CrmOpportunity::where('tipo', 'renovacao');
        $abertas  = (int) (clone $base)->where('status', 'aberto')->count();
        $ganhas   = (int) (clone $base)->where('status', 'ganho')->count();
        $perdidas = (int) (clone $base)->where('status', 'perdido')->count();
        $valorAberto = (float) (clone $base)->where('status', 'aberto')->sum('valor');

        return response()->json(['data' => [
            'contratos_proximos_vencimento' => $proximosVencimento,
            'renovacoes_abertas'  => $abertas,
            'renovacoes_ganhas'   => $ganhas,
            'renovacoes_perdidas' => $perdidas,
            'valor_em_renovacao'  => round($valorAberto, 2),
        ]]);
    }

    /**
     * Roadmap Fase 5 — Risco de Renovação: contratos próximos do vencimento (90/60/30/vencido)
     * + score de risco (Baixo/Médio/Alto/Crítico) combinando saúde da conta, utilização do
     * contrato, interações recentes, follow-ups vencidos e histórico de renovações.
     */
    public function riscoRenovacao(): JsonResponse
    {
        $hoje = now()->startOfDay();
        $limite = (clone $hoje)->addDays(90);

        $contratos = \App\Models\Contract::where('status', 'ativo')->whereNotNull('data_vencimento')
            ->whereNotNull('project_id')->whereDate('data_vencimento', '<=', $limite)
            ->with('customer:id,name,crm_status', 'contractType:id,name')
            ->get();

        $custIds = $contratos->pluck('customer_id')->unique();
        $saudeMap = \App\Models\CrmAccountHealthSnapshot::whereIn('customer_id', $custIds)
            ->orderByDesc('id')->get()->groupBy('customer_id')->map->first();
        $fatorCache = [];
        $fatoresCliente = function (int $cid) use (&$fatorCache, $saudeMap, $hoje) {
            if (isset($fatorCache[$cid])) return $fatorCache[$cid];
            $projIds = \App\Models\Project::where('customer_id', $cid)->whereNull('deleted_at')->pluck('id');
            $contr = (float) \App\Models\Project::whereIn('id', $projIds)->sum('sold_hours');
            $cons = (float) \App\Models\Timesheet::whereIn('project_id', $projIds)->whereNull('deleted_at')->whereIn('status', ['approved', 'pending'])->sum('effort_minutes') / 60;
            $util = $contr > 0 ? $cons / $contr * 100 : 0;
            $oppIds = CrmOpportunity::where('customer_id', $cid)->pluck('id');
            $ult = collect([
                \App\Models\CustomerCrmProfile::where('customer_id', $cid)->value('ultima_interacao_at'),
                CrmOpportunity::where('customer_id', $cid)->max('ultima_interacao_at'),
            ])->filter()->max();
            $diasInter = $ult ? (int) \Illuminate\Support\Carbon::parse($ult)->diffInDays($hoje) : 999;
            $fuVenc = $oppIds->isNotEmpty() && \App\Models\CrmTask::whereIn('opportunity_id', $oppIds)->whereNull('concluida_at')->whereNotNull('data')->whereDate('data', '<', $hoje)->exists();
            $renoPerdida = CrmOpportunity::where('customer_id', $cid)->where('tipo', 'renovacao')->where('status', 'perdido')->exists();
            $saude = $saudeMap[$cid]->status ?? null;
            return $fatorCache[$cid] = compact('util', 'diasInter', 'fuVenc', 'renoPerdida', 'saude');
        };

        $buckets = ['vencido' => 0, 'vence_30' => 0, 'vence_60' => 0, 'vence_90' => 0];
        $risco = ['baixo' => 0, 'medio' => 0, 'alto' => 0, 'critico' => 0];
        $lista = $contratos->map(function ($ct) use ($hoje, $fatoresCliente, &$buckets, &$risco) {
            $dias = (int) $hoje->diffInDays(\Illuminate\Support\Carbon::parse($ct->data_vencimento), false);
            $bk = $dias < 0 ? 'vencido' : ($dias <= 30 ? 'vence_30' : ($dias <= 60 ? 'vence_60' : 'vence_90'));
            $buckets[$bk]++;
            $f = $fatoresCliente($ct->customer_id);

            $score = ($dias < 0 ? 3 : ($dias <= 30 ? 2 : ($dias <= 60 ? 1 : 0)))
                + ($f['saude'] === 'critico' ? 3 : ($f['saude'] === 'atencao' ? 1 : 0))
                + ($f['util'] > 90 ? 2 : 0)
                + ($f['diasInter'] >= 60 ? 2 : ($f['diasInter'] >= 30 ? 1 : 0))
                + ($f['fuVenc'] ? 1 : 0)
                + ($f['renoPerdida'] ? 1 : 0);
            $r = ($dias < 0 || $score >= 6) ? 'critico' : ($score >= 4 ? 'alto' : ($score >= 2 ? 'medio' : 'baixo'));
            $risco[$r]++;
            return [
                'contract_id' => $ct->id, 'projeto' => $ct->project_name ?: ('Contrato #' . $ct->id),
                'cliente' => $ct->customer?->name, 'customer_id' => $ct->customer_id,
                'tipo' => $ct->contractType?->name, 'data_vencimento' => optional($ct->data_vencimento)->toDateString(),
                'dias' => $dias, 'valor' => (float) ($ct->valor_projeto ?? $ct->valor_hora ?? 0),
                'utilizacao_pct' => round($f['util'], 0), 'dias_sem_interacao' => $f['diasInter'] === 999 ? null : $f['diasInter'],
                'saude' => $f['saude'], 'score' => $score, 'risco' => $r,
            ];
        });
        $ordemR = ['critico' => 0, 'alto' => 1, 'medio' => 2, 'baixo' => 3];
        $lista = $lista->sortBy(fn ($x) => ($ordemR[$x['risco']] ?? 9) * 1000 - $x['score'])->values();

        return response()->json(['data' => ['buckets' => $buckets, 'risco' => $risco, 'contratos' => $lista]]);
    }

    /** CRM — métricas da camada de LEADS (captação + qualificação). */
    public function leads(): JsonResponse
    {
        $p = DB::table('customer_crm_profiles');
        $criados      = (clone $p)->whereNotNull('lead_created_at')->count();
        $qualificados = (clone $p)->whereNotNull('qualified_at')->count();
        $perdidos     = (clone $p)->whereNotNull('lost_at')->count();
        $taxa = $criados > 0 ? round($qualificados / $criados * 100, 1) : 0.0;

        $tempoMedio = (float) (DB::table('customer_crm_profiles')
            ->whereNotNull('qualified_at')->whereNotNull('lead_created_at')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (qualified_at - lead_created_at)) / 86400) as d")->value('d') ?? 0);

        // Leads atuais (crm_status='lead', não perdidos) sem próxima ação / sem atividade
        $ativos = DB::table('customers as c')->join('customer_crm_profiles as p', 'p.customer_id', '=', 'c.id')
            ->where('c.crm_status', 'lead')->whereNull('c.deleted_at')->whereNull('p.lost_at');
        $semProxima   = (clone $ativos)->whereNull('p.proxima_acao_at')->count();
        $semAtividade = (clone $ativos)->whereNull('p.ultima_interacao_at')->count();

        // Por origem: leads / qualificados / conversão + receita gerada (oportunidades ganhas)
        $porOrigem = DB::table('crm_lead_sources as s')
            ->leftJoin('customer_crm_profiles as p', 'p.lead_source_id', '=', 's.id')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('s.company_id', $cid))
            ->selectRaw("s.id, s.name,
                COUNT(p.customer_id) FILTER (WHERE p.lead_created_at IS NOT NULL) as leads,
                COUNT(p.customer_id) FILTER (WHERE p.qualified_at IS NOT NULL) as qualificados")
            ->groupBy('s.id', 's.name')->orderByDesc('leads')->get();

        $receitaPorOrigem = DB::table('crm_opportunities as o')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->join('customer_crm_profiles as p', 'p.customer_id', '=', 'c.id')
            ->where('o.status', 'ganho')->whereNull('o.deleted_at')->whereNotNull('p.lead_source_id')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->selectRaw('p.lead_source_id, SUM(o.valor) as receita')
            ->groupBy('p.lead_source_id')->pluck('receita', 'lead_source_id');

        $porOrigem = $porOrigem->map(fn ($r) => [
            'origem'       => $r->name,
            'leads'        => (int) $r->leads,
            'qualificados' => (int) $r->qualificados,
            'conversao'    => $r->leads > 0 ? round($r->qualificados / $r->leads * 100, 1) : 0.0,
            'receita'      => (float) ($receitaPorOrigem[$r->id] ?? 0),
        ])->values();

        return response()->json(['data' => [
            'leads_criados'                => $criados,
            'leads_qualificados'           => $qualificados,
            'leads_perdidos'               => $perdidos,
            'taxa_conversao'               => $taxa,
            'tempo_medio_qualificacao_dias' => round($tempoMedio, 1),
            'sem_proxima_acao'             => $semProxima,
            'sem_atividade'                => $semAtividade,
            'por_origem'                   => $porOrigem,
        ]]);
    }

    /**
     * FORECAST / DASHBOARD DO GESTOR COMERCIAL — visão de previsibilidade consolidada:
     * KPIs, forecast do mês (negociação/ponderado/fechado/previsto), por responsável,
     * alertas de gestão, oportunidades em risco e tempo médio por etapa (gargalos).
     */
    public function forecast(): JsonResponse
    {
        $health = app(\App\Services\OpportunityHealthService::class);
        $iniMes = now()->startOfMonth();
        $fimMes = now()->endOfMonth();

        $abertas = CrmOpportunity::where('status', 'aberto')
            ->with(['stage:id,name,probabilidade,sla_dias', 'customer:id,name', 'responsavel:id,name'])->get();
        $probDe = fn ($o) => $o->probabilidade !== null ? (int) $o->probabilidade : (int) ($o->stage?->probabilidade ?? 0);
        $pondDe = fn ($o) => round((float) $o->valor * $probDe($o) / 100, 2);

        $valorNegociacao = round((float) $abertas->sum('valor'), 2);
        $valorPonderado  = round((float) $abertas->sum($pondDe), 2);
        $fechadoMes = round((float) CrmOpportunity::where('status', 'ganho')
            ->whereBetween('fechamento_at', [$iniMes, $fimMes])->sum('valor'), 2);
        $pondMes = round((float) $abertas->filter(fn ($o) => $o->previsao_fechamento && $o->previsao_fechamento->between($iniMes, $fimMes))->sum($pondDe), 2);
        $previstoMes = round($fechadoMes + $pondMes, 2);

        $leadsAtivos = \App\Models\Customer::where('crm_status', 'lead')->count();
        $propAguardando = \App\Models\CrmProposal::whereNull('deleted_at')->where('status', 'aguardando_assinatura')->count();

        // META / QUOTA — dá contexto ao forecast (gap, % atingido).
        $periodo = now()->format('Y-m');
        $targets = \App\Models\CrmSalesTarget::where('periodo', $periodo)->get();
        $metaEmpresa = (float) ($targets->firstWhere('user_id', null)?->valor_meta ?? 0);
        $metaPorUser = $targets->whereNotNull('user_id')->pluck('valor_meta', 'user_id');
        $ganhoPorUser = CrmOpportunity::where('status', 'ganho')->whereBetween('fechamento_at', [$iniMes, $fimMes])
            ->selectRaw('responsavel_id, sum(valor) as v')->groupBy('responsavel_id')->pluck('v', 'responsavel_id');
        $gap = round($metaEmpresa - $previstoMes, 2);
        $pctAtingido = $metaEmpresa > 0 ? round($fechadoMes / $metaEmpresa * 100, 1) : null;
        $coverage = $metaEmpresa > 0 ? round($valorNegociacao / $metaEmpresa, 1) : null;

        // Saúde + alertas por oportunidade aberta
        $emRisco = []; $alertas = [];
        foreach ($abertas as $o) {
            $s = $health->compute($o);
            $diasSem = $o->ultima_interacao_at ? (int) $o->ultima_interacao_at->diffInDays(now()) : null;
            if ($s['status'] === 'em_risco') {
                $emRisco[] = ['opportunity_id' => $o->id, 'titulo' => $o->title, 'empresa' => $o->customer?->name,
                    'valor' => (float) $o->valor, 'ponderado' => $pondDe($o), 'dias_sem_interacao' => $diasSem,
                    'motivos' => $s['motivos'], 'diagnostico' => $s['diagnostico']];
            }
            $base = ['opportunity_id' => $o->id, 'titulo' => $o->title, 'empresa' => $o->customer?->name, 'valor_risco' => (float) $o->valor];
            // Close date VENCIDO e ainda aberto = forecast sujo (prioridade máxima de saneamento).
            if ($o->previsao_fechamento && $o->previsao_fechamento->endOfDay()->isPast()) {
                $alertas[] = $base + ['severidade' => 'alto', 'tipo' => 'forecast_vencido', 'texto' => 'Forecast vencido — fechamento previsto já passou'];
            }
            if ($diasSem !== null && $diasSem >= 15) $alertas[] = $base + ['severidade' => 'alto', 'tipo' => 'parada', 'texto' => "Parada há {$diasSem} dias"];
            if ($o->proxima_acao_at === null) $alertas[] = $base + ['severidade' => 'alto', 'tipo' => 'sem_proxima', 'texto' => 'Sem próxima ação'];
            elseif ($o->proxima_acao_at->isTomorrow()) $alertas[] = $base + ['severidade' => 'medio', 'tipo' => 'vence_amanha', 'texto' => 'Próxima ação vence amanhã'];
        }
        // Proposta enviada há ≥10 dias sem evolução
        \App\Models\CrmProposal::whereNull('deleted_at')->where('status', 'enviada')
            ->where('updated_at', '<', now()->subDays(10))->with('opportunity.customer:id,name')->get()
            ->each(function ($p) use (&$alertas) {
                $alertas[] = ['opportunity_id' => $p->opportunity_id, 'titulo' => $p->codigo, 'empresa' => optional($p->opportunity?->customer)->name,
                    'severidade' => 'alto', 'tipo' => 'proposta_parada', 'texto' => 'Proposta enviada há ' . (int) $p->updated_at->diffInDays(now()) . ' dias'];
            });

        // PERFORMANCE por vendedor (win rate / ticket médio / ciclo médio) — all-time.
        $perf = DB::table('crm_opportunities')->whereNull('deleted_at')->whereNotNull('responsavel_id')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('crm_opportunities.company_id', $cid))
            ->selectRaw("responsavel_id,
                sum(case when status='ganho' then 1 else 0 end) as ganhas,
                sum(case when status='perdido' then 1 else 0 end) as perdidas,
                avg(case when status='ganho' then valor end) as ticket,
                avg(case when status='ganho' and fechamento_at is not null and data_abertura is not null then EXTRACT(EPOCH FROM (fechamento_at - data_abertura))/86400 end) as ciclo")
            ->groupBy('responsavel_id')->get()->keyBy('responsavel_id');

        $porResp = $abertas->groupBy(fn ($o) => $o->responsavel_id ?? 0)
            ->map(function ($g) use ($pondDe, $metaPorUser, $ganhoPorUser, $perf) {
                $rid = $g->first()->responsavel_id;
                $p = $perf[$rid] ?? null;
                $ganhas = (int) ($p->ganhas ?? 0); $perdidas = (int) ($p->perdidas ?? 0);
                return ['responsavel' => $g->first()->responsavel?->name ?? '—', 'user_id' => $rid,
                    'abertas' => $g->count(), 'valor' => round((float) $g->sum('valor'), 2),
                    'ponderado' => round((float) $g->sum($pondDe), 2),
                    'meta' => (float) ($metaPorUser[$rid] ?? 0), 'realizado' => (float) ($ganhoPorUser[$rid] ?? 0),
                    'win_rate' => ($ganhas + $perdidas) > 0 ? round($ganhas / ($ganhas + $perdidas) * 100, 1) : null,
                    'ticket_medio' => $p && $p->ticket ? round((float) $p->ticket, 2) : null,
                    'ciclo_medio' => $p && $p->ciclo ? round((float) $p->ciclo, 1) : null];
            })->sortByDesc('ponderado')->values();

        // GARGALOS por etapa do pipeline — mediana de dias na etapa + valor preso (deals abertos).
        $entradas = [];
        \App\Models\CrmOpportunityEvent::whereIn('opportunity_id', $abertas->pluck('id'))
            ->where('event_type', 'stage_changed')->orderBy('created_at')
            ->get(['opportunity_id', 'to_value', 'created_at'])
            ->each(function ($e) use (&$entradas) { $entradas[$e->opportunity_id][$e->to_value] = $e->created_at; });
        $diasEtapa = function ($o) use ($entradas) {
            $en = $entradas[$o->id][$o->stage?->name] ?? $o->created_at;
            return $en ? (int) \Illuminate\Support\Carbon::parse($en)->diffInDays(now()) : 0;
        };
        $mediana = function ($vals) {
            $v = $vals->sort()->values(); $n = $v->count();
            if ($n === 0) return 0;
            return $n % 2 ? (float) $v[intdiv($n, 2)] : round(($v[$n / 2 - 1] + $v[$n / 2]) / 2, 1);
        };
        $gargalos = $abertas->groupBy(fn ($o) => $o->stage?->name ?? '—')
            ->map(fn ($g, $nome) => ['etapa' => $nome, 'abertas' => $g->count(),
                'valor_preso' => round((float) $g->sum('valor'), 2), 'mediana_dias' => $mediana($g->map($diasEtapa))])
            ->sortByDesc('valor_preso')->values();

        // Alertas priorizados por R$ em risco.
        $alertas = collect($alertas)->sortByDesc(fn ($a) => $a['valor_risco'] ?? 0)->values()->all();

        // CATEGORIAS DE FORECAST (Commit ⊆ Best Case ⊆ Pipeline; Omitido fora do forecast).
        $catDe = fn ($o) => $o->forecast_categoria ?: 'pipeline';
        $catVal = fn (array $cats) => round((float) $abertas->filter(fn ($o) => in_array($catDe($o), $cats))->sum('valor'), 2);
        $categorias = [
            'commit'    => $catVal(['commit']),
            'best_case' => $catVal(['commit', 'best_case']),
            'pipeline'  => $catVal(['commit', 'best_case', 'pipeline']),
            'omitido'   => $catVal(['omitido']),
        ];

        // TENDÊNCIA / SLIPPAGE — compara com o último snapshot da competência.
        $snaps = \App\Models\CrmForecastSnapshot::where('periodo', $periodo)->orderBy('data')->get();
        $ultimo = $snaps->last();
        $tendencia = [
            'delta_previsto' => $ultimo ? round($previstoMes - (float) $ultimo->previsto, 2) : null,
            'delta_commit'   => $ultimo ? round($categorias['commit'] - (float) $ultimo->commit, 2) : null,
            'desde'          => $ultimo?->data?->toDateString(),
            'historico'      => $snaps->map(fn ($s) => ['data' => $s->data->toDateString(), 'previsto' => (float) $s->previsto, 'commit' => (float) $s->commit, 'fechado' => (float) $s->fechado])->values(),
        ];

        $meses = [1 => 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        return response()->json(['data' => [
            'mes' => $meses[(int) now()->format('n')] . '/' . now()->format('Y'),
            'meta' => ['valor' => $metaEmpresa, 'previsto' => $previstoMes, 'realizado' => $fechadoMes, 'gap' => $gap, 'pct_atingido' => $pctAtingido, 'coverage' => $coverage],
            'kpis' => [
                'leads_ativos' => $leadsAtivos, 'oportunidades_abertas' => $abertas->count(),
                'valor_negociacao' => $valorNegociacao, 'valor_ponderado' => $valorPonderado,
                'valor_fechado_mes' => $fechadoMes, 'previsto_mes' => $previstoMes,
                'meta_mes' => $metaEmpresa, 'gap_meta' => $gap, 'pct_atingido' => $pctAtingido,
                'propostas_aguardando_assinatura' => $propAguardando, 'em_risco' => count($emRisco),
            ],
            'forecast' => ['negociacao' => $valorNegociacao, 'ponderado' => $valorPonderado, 'fechado' => $fechadoMes, 'previsto_mes' => $previstoMes],
            'por_responsavel' => $porResp,
            'alertas' => $alertas,
            'oportunidades_risco' => $emRisco,
            'tempo_por_etapa' => $this->tempoPorEtapaMacro(),
            'funil' => $this->funilData(),
            'gargalos' => $gargalos,
            'categorias' => $categorias,
            'tendencia' => $tendencia,
        ]]);
    }

    /** Congela o forecast da competência atual num snapshot (semanal / sob demanda). */
    public function snapshot(): JsonResponse
    {
        $iniMes = now()->startOfMonth(); $fimMes = now()->endOfMonth();
        $periodo = now()->format('Y-m');
        $abertas = CrmOpportunity::where('status', 'aberto')->with('stage:id,probabilidade')->get();
        $probDe = fn ($o) => $o->probabilidade !== null ? (int) $o->probabilidade : (int) ($o->stage?->probabilidade ?? 0);
        $negociacao = (float) $abertas->sum('valor');
        $pondMes = (float) $abertas->filter(fn ($o) => $o->previsao_fechamento && $o->previsao_fechamento->between($iniMes, $fimMes))->sum(fn ($o) => (float) $o->valor * $probDe($o) / 100);
        $fechado = (float) CrmOpportunity::where('status', 'ganho')->whereBetween('fechamento_at', [$iniMes, $fimMes])->sum('valor');
        $meta = (float) (\App\Models\CrmSalesTarget::where('periodo', $periodo)->whereNull('user_id')->value('valor_meta') ?? 0);
        $catDe = fn ($o) => $o->forecast_categoria ?: 'pipeline';
        $catVal = fn (array $c) => (float) $abertas->filter(fn ($o) => in_array($catDe($o), $c))->sum('valor');

        $snap = \App\Models\CrmForecastSnapshot::updateOrCreate(
            ['periodo' => $periodo, 'data' => now()->toDateString()],
            ['meta' => $meta, 'previsto' => round($fechado + $pondMes, 2), 'commit' => $catVal(['commit']),
             'best_case' => $catVal(['commit', 'best_case']), 'pipeline' => $catVal(['commit', 'best_case', 'pipeline']),
             'fechado' => round($fechado, 2), 'coverage' => $meta > 0 ? round($negociacao / $meta, 2) : null],
        );
        return response()->json(['data' => $snap]);
    }

    /** Tempo médio (dias) por macro-etapa do funil — identifica gargalos. */
    private function tempoPorEtapaMacro(): array
    {
        $d = fn ($q) => 'EXTRACT(EPOCH FROM (' . $q . '))/86400';
        $lead = DB::table('customer_crm_profiles')->whereNotNull('qualified_at')->whereNotNull('lead_created_at')
            ->avg(DB::raw($d('qualified_at - lead_created_at')));
        $prospect = DB::table('crm_opportunities as o')->join('customer_crm_profiles as p', 'p.customer_id', '=', 'o.customer_id')
            ->whereNotNull('p.qualified_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('o.company_id', $cid))
            ->avg(DB::raw($d('o.created_at - p.qualified_at')));
        $oppProp = DB::table('crm_proposals as pr')->join('crm_opportunities as o', 'o.id', '=', 'pr.opportunity_id')
            ->whereNull('pr.deleted_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('pr.company_id', $cid))
            ->avg(DB::raw($d('pr.created_at - o.created_at')));
        $fech = DB::table('crm_opportunities')->where('status', 'ganho')->whereNotNull('fechamento_at')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('crm_opportunities.company_id', $cid))
            ->avg(DB::raw($d('fechamento_at - created_at')));
        $r = fn ($x) => $x === null ? null : round(max(0, (float) $x), 1);
        return [
            ['etapa' => 'Lead → Prospect', 'dias' => $r($lead)],
            ['etapa' => 'Prospect → Oportunidade', 'dias' => $r($prospect)],
            ['etapa' => 'Oportunidade → Proposta', 'dias' => $r($oppProp)],
            ['etapa' => 'Proposta → Fechamento', 'dias' => $r($fech)],
        ];
    }
}
