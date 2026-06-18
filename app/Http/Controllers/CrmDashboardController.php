<?php

namespace App\Http\Controllers;

use App\Models\CrmOpportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** CRM — métricas do dashboard comercial. */
class CrmDashboardController extends Controller
{
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
            ->selectRaw("COALESCE(p.segment, 'Sem segmento') as segment, SUM(o.valor) as valor")
            ->groupBy('segment')->orderByDesc('valor')->get()
            ->map(fn ($r) => ['segment' => $r->segment, 'valor' => (float) $r->valor]);

        // Receita ganha por produto
        $porProduto = DB::table('crm_opportunity_products as op')
            ->join('crm_opportunities as o', 'o.id', '=', 'op.opportunity_id')
            ->join('crm_products as pr', 'pr.id', '=', 'op.crm_product_id')
            ->where('o.status', 'ganho')->whereNull('o.deleted_at')
            ->selectRaw('pr.name as produto, SUM(op.valor) as valor')
            ->groupBy('pr.name')->orderByDesc('valor')->get()
            ->map(fn ($r) => ['produto' => $r->produto, 'valor' => (float) $r->valor]);

        // Receita ganha por categoria de produto
        $porCategoria = DB::table('crm_opportunity_products as op')
            ->join('crm_opportunities as o', 'o.id', '=', 'op.opportunity_id')
            ->join('crm_products as pr', 'pr.id', '=', 'op.crm_product_id')
            ->where('o.status', 'ganho')->whereNull('o.deleted_at')
            ->selectRaw("COALESCE(pr.categoria, 'Sem categoria') as categoria, SUM(op.quantidade * op.valor) as valor")
            ->groupBy('categoria')->orderByDesc('valor')->get()
            ->map(fn ($r) => ['categoria' => $r->categoria, 'valor' => (float) $r->valor]);

        // Item 2 — perdas por motivo / executivo / produto
        $perdasPorMotivo = DB::table('crm_opportunities as o')
            ->leftJoin('crm_loss_reasons as r', 'r.id', '=', 'o.loss_reason_id')
            ->where('o.status', 'perdido')->whereNull('o.deleted_at')
            ->selectRaw("COALESCE(r.name, 'Sem motivo') as motivo, COUNT(*) as qtd, COALESCE(SUM(o.valor),0) as valor")
            ->groupBy('r.name')->orderByDesc('qtd')->get()
            ->map(fn ($r) => ['motivo' => $r->motivo, 'qtd' => (int) $r->qtd, 'valor' => (float) $r->valor]);

        $perdasPorExecutivo = DB::table('crm_opportunities as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.responsavel_id')
            ->where('o.status', 'perdido')->whereNull('o.deleted_at')
            ->selectRaw("COALESCE(u.name, '—') as executivo, COUNT(*) as qtd")
            ->groupBy('u.name')->orderByDesc('qtd')->get()
            ->map(fn ($r) => ['executivo' => $r->executivo, 'qtd' => (int) $r->qtd]);

        $perdasPorProduto = DB::table('crm_opportunity_products as op')
            ->join('crm_opportunities as o', 'o.id', '=', 'op.opportunity_id')
            ->join('crm_products as pr', 'pr.id', '=', 'op.crm_product_id')
            ->where('o.status', 'perdido')->whereNull('o.deleted_at')
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
        // Contagens dos estágios do ciclo comercial
        $leadsCriados = (int) DB::table('customer_crm_profiles')->whereNotNull('lead_created_at')->count();
        $prospects    = (int) DB::table('customer_crm_profiles')->whereNotNull('qualified_at')->count();
        $oppsAbertas  = (int) CrmOpportunity::where('status', 'aberto')->count();
        $oppsTotal    = (int) CrmOpportunity::count();
        $propostas    = (int) DB::table('crm_proposals')->whereNull('deleted_at')->count();
        $contratos    = (int) CrmOpportunity::whereNotNull('contract_id')->count();

        // Clientes que já tiveram oportunidade (base para Prospect→Oportunidade)
        $prospectsComOpp = (int) DB::table('crm_opportunities as o')
            ->join('customer_crm_profiles as p', 'p.customer_id', '=', 'o.customer_id')
            ->whereNull('o.deleted_at')->whereNotNull('p.qualified_at')
            ->distinct('o.customer_id')->count('o.customer_id');
        // Oportunidades que geraram proposta / que viraram contrato
        $oppsComProposta = (int) DB::table('crm_proposals')->whereNull('deleted_at')->distinct('opportunity_id')->count('opportunity_id');
        $propostasComContrato = (int) DB::table('crm_proposals as pr')
            ->join('crm_opportunities as o', 'o.id', '=', 'pr.opportunity_id')
            ->whereNull('pr.deleted_at')->whereNotNull('o.contract_id')->count();

        $pct = fn ($num, $den) => $den > 0 ? round($num / $den * 100, 1) : 0.0;

        // Tempos médios (dias)
        $diasLeadProspect = (float) (DB::table('customer_crm_profiles')
            ->whereNotNull('qualified_at')->whereNotNull('lead_created_at')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (qualified_at - lead_created_at))/86400) as d")->value('d') ?? 0);
        // Prospect→Oportunidade: 1ª oportunidade do cliente vs qualified_at
        $diasProspectOpp = (float) (DB::table('crm_opportunities as o')
            ->join('customer_crm_profiles as p', 'p.customer_id', '=', 'o.customer_id')
            ->whereNull('o.deleted_at')->whereNotNull('p.qualified_at')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (o.created_at - p.qualified_at))/86400) as d")->value('d') ?? 0);
        // Oportunidade→Contrato: contrato gerado vs criação da oportunidade
        $diasOppContrato = (float) (DB::table('crm_opportunities as o')
            ->join('contracts as c', 'c.id', '=', 'o.contract_id')
            ->whereNull('o.deleted_at')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (c.created_at - o.created_at))/86400) as d")->value('d') ?? 0);

        return response()->json(['data' => [
            'etapas' => [
                ['etapa' => 'Leads',         'qtd' => $leadsCriados],
                ['etapa' => 'Prospects',     'qtd' => $prospects],
                ['etapa' => 'Oportunidades', 'qtd' => $oppsTotal],
                ['etapa' => 'Propostas',     'qtd' => $propostas],
                ['etapa' => 'Contratos',     'qtd' => $contratos],
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
        ]]);
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
            ->selectRaw("s.id, s.name,
                COUNT(p.customer_id) FILTER (WHERE p.lead_created_at IS NOT NULL) as leads,
                COUNT(p.customer_id) FILTER (WHERE p.qualified_at IS NOT NULL) as qualificados")
            ->groupBy('s.id', 's.name')->get()->keyBy('id');

        // Oportunidades / ganhas / contratos / receita / tempo de fechamento por origem.
        $opp = DB::table('crm_opportunities as o')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->join('customer_crm_profiles as p', 'p.customer_id', '=', 'c.id')
            ->whereNull('o.deleted_at')->whereNotNull('p.lead_source_id')
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
        $comercial = ['lead', 'prospect', 'cliente', 'contrato_ativo', 'em_renovacao'];

        $customers = \App\Models\Customer::whereNull('deleted_at')->whereIn('crm_status', $comercial)
            ->with(['crmProfile:customer_id,ultima_interacao_at,proxima_acao_at', 'executive:id,name'])
            ->when($request->filled('executivo_id'), fn ($q) => $q->where('executive_id', $request->executivo_id))
            ->get();

        // Agregados de oportunidade por cliente (1 query).
        $oppAgg = DB::table('crm_opportunities')->whereNull('deleted_at')
            ->whereIn('customer_id', $customers->pluck('id'))
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
            ->selectRaw("s.id, s.name,
                COUNT(p.customer_id) FILTER (WHERE p.lead_created_at IS NOT NULL) as leads,
                COUNT(p.customer_id) FILTER (WHERE p.qualified_at IS NOT NULL) as qualificados")
            ->groupBy('s.id', 's.name')->orderByDesc('leads')->get();

        $receitaPorOrigem = DB::table('crm_opportunities as o')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->join('customer_crm_profiles as p', 'p.customer_id', '=', 'c.id')
            ->where('o.status', 'ganho')->whereNull('o.deleted_at')->whereNotNull('p.lead_source_id')
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
}
