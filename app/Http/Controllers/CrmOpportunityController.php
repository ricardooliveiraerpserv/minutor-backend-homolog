<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityEvent;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\CrmProposalCalc;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRM — Oportunidades: kanban por funil, CRUD, mudança de etapa, ganho/perda. */
class CrmOpportunityController extends Controller
{
    private function withRels($q)
    {
        return $q->with(['customer:id,name', 'pipeline:id,name', 'stage:id,name,is_won,is_lost,probabilidade', 'responsavel:id,name']);
    }

    /** Indicador de cada oportunidade: próxima ação + probabilidade/forecast (Item 2). */
    private function decorate(CrmOpportunity $o): array
    {
        // Probabilidade EFETIVA = override manual da oportunidade, senão a da etapa.
        $stageProb = $o->relationLoaded('stage') ? (int) ($o->stage?->probabilidade ?? 0) : 0;
        $prob = $o->probabilidade !== null ? (int) $o->probabilidade : $stageProb;
        $aberto = $o->status === 'aberto';
        $diasSemInteracao = $o->ultima_interacao_at ? (int) $o->ultima_interacao_at->diffInDays(now()) : null;
        $ponderado = round((float) $o->valor * $prob / 100, 2);
        return array_merge($o->toArray(), [
            'sem_proxima_acao'      => $aberto && $o->proxima_acao_at === null,
            'proxima_acao_vencida'  => $aberto && $o->proxima_acao_at !== null && $o->proxima_acao_at->isPast(),
            'dias_sem_interacao'    => $diasSemInteracao,
            'sem_interacao_7'       => $aberto && ($o->ultima_interacao_at === null || $diasSemInteracao >= 7),
            'probabilidade'         => $prob,
            'probabilidade_manual'  => $o->probabilidade,
            'probabilidade_etapa'   => $stageProb,
            'forecast'              => $ponderado,
            'valor_ponderado'       => $ponderado,
            'forecast_vencido'      => $aberto && $o->previsao_fechamento !== null && $o->previsao_fechamento->endOfDay()->isPast(),
        ]);
    }

    /** Filtros compartilhados pela lista analítica e pelo export (Item 2). */
    private function filtered(Request $request)
    {
        return $this->withRels(CrmOpportunity::query())
            ->when($request->filled('pipeline_id'), fn ($x) => $x->where('pipeline_id', $request->pipeline_id))
            ->when($request->filled('stage_id'), fn ($x) => $x->where('stage_id', $request->stage_id))
            ->when($request->filled('status'), fn ($x) => $x->where('status', $request->status))
            ->when($request->filled('responsavel_id'), fn ($x) => $x->where('responsavel_id', $request->responsavel_id))
            ->when($request->filled('customer_id'), fn ($x) => $x->where('customer_id', $request->customer_id))
            ->when($request->filled('produto_id'), fn ($x) => $x->whereHas('products', fn ($p) => $p->where('crm_products.id', $request->produto_id)))
            ->when($request->filled('de'), fn ($x) => $x->whereDate('data_abertura', '>=', $request->de))
            ->when($request->filled('ate'), fn ($x) => $x->whereDate('data_abertura', '<=', $request->ate))
            ->when($request->boolean('sem_proxima_acao'), fn ($x) => $x->where('status', 'aberto')->whereNull('proxima_acao_at'))
            // Origem (cadastro de Origens) e Motivo de perda
            ->when($request->filled('lead_source_id'), fn ($x) => $x->where('lead_source_id', $request->lead_source_id))
            ->when($request->filled('loss_reason_id'), fn ($x) => $x->where('loss_reason_id', $request->loss_reason_id))
            // Valor total (faixa)
            ->when($request->filled('valor_min'), fn ($x) => $x->where('valor', '>=', (float) $request->valor_min))
            ->when($request->filled('valor_max'), fn ($x) => $x->where('valor', '<=', (float) $request->valor_max))
            // Data de último contato (ultima_interacao_at)
            ->when($request->filled('lc_de'), fn ($x) => $x->whereDate('ultima_interacao_at', '>=', $request->lc_de))
            ->when($request->filled('lc_ate'), fn ($x) => $x->whereDate('ultima_interacao_at', '<=', $request->lc_ate))
            ->when($request->filled('search'), fn ($x) => $x->where('title', 'ilike', '%' . $request->search . '%'))
            ->orderByDesc('updated_at');
    }

    /** Kanban de um funil: etapas + oportunidades por etapa. */
    public function kanban(Request $request): JsonResponse
    {
        CrmPipelineController::ensureSeeded();
        $pipelineId = (int) $request->query('pipeline_id');
        if (!$pipelineId) {
            return response()->json(['message' => 'pipeline_id obrigatório'], 422);
        }
        $stages = CrmPipelineStage::where('pipeline_id', $pipelineId)->orderBy('ordem')->get();
        $opps = $this->withRels(CrmOpportunity::where('pipeline_id', $pipelineId))
            ->when($request->filled('responsavel_id'), fn ($q) => $q->where('responsavel_id', $request->responsavel_id))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->orderByDesc('updated_at')->get();

        // Fase 4 — "entrou na etapa em": último stage_changed p/ a etapa atual (1 query, sem N+1).
        $entradas = [];
        if ($opps->isNotEmpty()) {
            CrmOpportunityEvent::whereIn('opportunity_id', $opps->pluck('id'))
                ->where('event_type', 'stage_changed')->orderBy('created_at')
                ->get(['opportunity_id', 'to_value', 'created_at'])
                ->each(function ($e) use (&$entradas) { $entradas[$e->opportunity_id][$e->to_value] = $e->created_at; });
        }
        $diasNaEtapa = function ($o) use ($entradas) {
            $entrou = $entradas[$o->id][$o->stage?->name] ?? $o->created_at;
            return $entrou ? (int) \Illuminate\Support\Carbon::parse($entrou)->diffInDays(now()) : 0;
        };

        // Situação da proposta por oportunidade (1 query, sem N+1) — mesma escolha do syncOppValor:
        // proposta ativa mais recente COM valor (>0), senão a mais recente.
        $propMap = [];
        if ($opps->isNotEmpty()) {
            \App\Models\CrmProposal::whereIn('opportunity_id', $opps->pluck('id'))
                ->whereNull('deleted_at')->whereNotIn('status', ['cancelada', 'reprovada', 'expirada'])
                ->orderByDesc('versao')->orderByDesc('id')
                ->get(['id', 'opportunity_id', 'codigo', 'versao', 'tipo', 'status', 'valor', 'descontos'])
                ->groupBy('opportunity_id')
                ->each(function ($grp, $oid) use (&$propMap) {
                    $p = $grp->first(fn ($x) => (float) $x->total > 0) ?: $grp->first();
                    $propMap[$oid] = ['codigo' => $p->codigo, 'versao' => $p->versao, 'tipo' => $p->tipo, 'status' => $p->status, 'total' => (float) $p->total];
                });
        }

        // Próxima ATIVIDADE = próxima tarefa em aberto (com data), por oportunidade (1 query, sem N+1).
        $proxTarefa = [];
        if ($opps->isNotEmpty()) {
            $tiposNome = \App\Models\CrmContactType::pluck('nome', 'slug');
            \App\Models\CrmTask::whereIn('opportunity_id', $opps->pluck('id'))
                ->whereNull('concluida_at')->whereNotNull('data')
                ->orderBy('data')
                ->get(['id', 'opportunity_id', 'tipo', 'titulo', 'data'])
                ->groupBy('opportunity_id')
                ->each(function ($grp, $oid) use (&$proxTarefa, $tiposNome) {
                    $t = $grp->first(); // a mais próxima (ordenada por data asc)
                    $proxTarefa[$oid] = ['tipo' => $tiposNome[$t->tipo] ?? $t->tipo, 'titulo' => $t->titulo, 'data' => optional($t->data)->toIso8601String()];
                });
        }

        $health = app(\App\Services\OpportunityHealthService::class);
        $byStage = $opps->groupBy('stage_id');
        $cols = $stages->map(function ($s) use ($byStage, $diasNaEtapa, $propMap, $proxTarefa, $health) {
            $lista = $byStage[$s->id] ?? collect();
            $abertas = $lista->where('status', 'aberto');
            $prob = (int) ($s->probabilidade ?? 0);
            $valor = (float) $lista->sum('valor');
            $diasArr = $abertas->map($diasNaEtapa);
            return [
                'stage'              => $s,
                'opportunities'      => $lista->map(fn ($o) => array_merge($this->decorate($o), ['dias_na_etapa' => $diasNaEtapa($o), 'proposta' => $propMap[$o->id] ?? null, 'proxima_tarefa' => $proxTarefa[$o->id] ?? null, 'saude' => $health->compute($o, $diasNaEtapa($o), ['proposta_enviada' => isset($propMap[$o->id]) && !in_array($propMap[$o->id]['status'] ?? '', ['em_elaboracao'])])]))->values(),
                'count'              => $lista->count(),
                'total_valor'        => round($valor, 2),
                'forecast'           => round($valor * $prob / 100, 2),
                'tempo_medio_dias'   => $diasArr->isNotEmpty() ? round((float) $diasArr->avg(), 1) : 0,
                'vencidos'           => $s->sla_dias ? $abertas->filter(fn ($o) => $diasNaEtapa($o) > $s->sla_dias)->count() : 0,
                'sem_proxima_acao'   => $abertas->whereNull('proxima_acao_at')->count(),
                'parados'            => $abertas->filter(fn ($o) => $o->ultima_interacao_at === null || $o->ultima_interacao_at->lt(now()->subDays(7)))->count(),
            ];
        });
        return response()->json(['data' => ['stages' => $cols]]);
    }

    public function index(Request $request): JsonResponse
    {
        $opps = $this->filtered($request)->get();
        $health = app(\App\Services\OpportunityHealthService::class);
        // Dias na etapa em lote (sem N+1) — reusa o mesmo padrão do kanban.
        $entradas = [];
        \App\Models\CrmOpportunityEvent::whereIn('opportunity_id', $opps->pluck('id'))
            ->where('event_type', 'stage_changed')->orderBy('created_at')
            ->get(['opportunity_id', 'to_value', 'created_at'])
            ->each(function ($e) use (&$entradas) { $entradas[$e->opportunity_id][$e->to_value] = $e->created_at; });
        $diasNaEtapa = function ($o) use ($entradas) {
            $en = $entradas[$o->id][$o->stage?->name] ?? $o->created_at;
            return $en ? (int) \Illuminate\Support\Carbon::parse($en)->diffInDays(now()) : 0;
        };
        // Quais têm proposta enviada (p/ a saúde MEDDIC), em lote.
        $comProposta = \App\Models\CrmProposal::whereIn('opportunity_id', $opps->pluck('id'))->whereNull('deleted_at')
            ->whereNotIn('status', ['em_elaboracao', 'cancelada', 'reprovada', 'expirada'])->pluck('opportunity_id')->flip();
        return response()->json(['data' => $opps->map(fn ($o) => array_merge($this->decorate($o), [
            'saude' => $health->compute($o, $diasNaEtapa($o), ['proposta_enviada' => $comProposta->has($o->id)]),
        ]))]);
    }

    /** Export CSV da lista analítica (Item 2). Respeita os mesmos filtros. */
    public function export(Request $request)
    {
        $rows = $this->filtered($request)->get();
        $headers = ['Empresa', 'Oportunidade', 'Pipeline', 'Etapa', 'Responsável', 'Valor', 'Probabilidade %', 'Forecast', 'Última interação', 'Próxima ação', 'Status', 'Criada em'];
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM p/ Excel
        fputcsv($out, $headers, ';');
        foreach ($rows as $o) {
            $prob = (int) ($o->stage?->probabilidade ?? 0);
            fputcsv($out, [
                $o->customer?->name, $o->title, $o->pipeline?->name, $o->stage?->name,
                $o->responsavel?->name, number_format((float) $o->valor, 2, ',', '.'), $prob,
                number_format((float) $o->valor * $prob / 100, 2, ',', '.'),
                optional($o->ultima_interacao_at)->format('d/m/Y'), optional($o->proxima_acao_at)->format('d/m/Y'),
                $o->status, optional($o->created_at)->format('d/m/Y'),
            ], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="oportunidades.csv"',
        ]);
    }

    public function show(CrmOpportunity $opportunity): JsonResponse
    {
        // Auto-heal: se o valor do card está zerado mas há proposta com valor, ressincroniza (o sync normal
        // roda ao editar/gerar a proposta; isto cobre cards que ficaram defasados antes desse fluxo existir).
        if ((float) $opportunity->valor === 0.0) {
            app(\App\Documents\CrmProposalService::class)->syncOppValor(
                (new \App\Models\CrmProposal(['opportunity_id' => $opportunity->id]))->setRelation('opportunity', $opportunity)
            );
            $opportunity->refresh();
        }
        $opportunity->load(['customer:id,name,cgc', 'pipeline:id,name', 'stage', 'responsavel:id,name',
            'leadSource:id,name', 'contato:id,name,email,phone,whatsapp', 'lossReason:id,name', 'campaign:id,name',
            'products', 'tasks.responsavel:id,name', 'events.triggeredBy:id,name', 'contract:id,status,project_code_preview']);
        $health = app(\App\Services\OpportunityHealthService::class);
        $diasNaEtapa = $health->diasNaEtapa($opportunity);
        return response()->json(['data' => array_merge($this->decorate($opportunity), [
            'derivado'      => $this->derivarDaProposta($opportunity),
            'dias_na_etapa' => $diasNaEtapa,
            'saude'         => $health->compute($opportunity, $diasNaEtapa),
        ])]);
    }

    /**
     * Dados derivados da PROPOSTA/CONTRATO vinculados — preenchem o card automaticamente (read-only):
     * tipo, horas, % margem/custo, escopo, condição de pagamento, código do projeto, valor, status/assinatura.
     */
    private function derivarDaProposta(CrmOpportunity $o): array
    {
        // Mesma escolha do syncOppValor: proposta ativa mais recente COM valor (>0), senão a mais recente.
        $ativas = \App\Models\CrmProposal::with('calc')->where('opportunity_id', $o->id)
            ->whereNull('deleted_at')->whereNotIn('status', ['cancelada', 'reprovada', 'expirada'])
            ->orderByDesc('versao')->orderByDesc('id')->get();
        $p = $ativas->first(fn ($x) => (float) $x->total > 0) ?: $ativas->first();
        if (!$p) return [];
        $labels = ['bh_fixo' => 'Banco de Horas Fixo', 'bh_mensal' => 'Banco de Horas Mensal', 'on_demand' => 'Consultoria Sob Demanda', 'projeto_fechado' => 'Projeto Fechado', 'cloud' => 'Cloud Protheus'];
        $in = (array) ($p->calc->inputs ?? []);
        $out = (array) ($p->calc->outputs ?? []);
        $cont = (array) ($p->conteudo ?? []);
        $pct = fn ($v) => $v === null ? null : round((float) $v * (((float) $v) <= 1 ? 100 : 1), 1) . '%';
        return array_filter([
            'codigo_projeto'    => $p->codigo ?: optional($o->contract)->project_code_preview,
            'tipo_contrato'     => $labels[$p->tipo] ?? $p->tipo,
            'modo_faturamento'  => $p->calc->modo_faturamento ?? null,
            'valor_proposta'    => (float) $p->total > 0 ? 'R$ ' . number_format((float) $p->total, 2, ',', '.') : null,
            'horas_consultoria' => $in['horas_consultoria'] ?? $in['horas'] ?? null,
            'horas_coordenacao' => $in['horas_coordenacao'] ?? ($out['horas_coordenacao'] ?? null),
            'margem_liquida'    => $pct($out['margem'] ?? ($in['margem_pct'] ?? null)),
            'custo_fixo'        => $pct($in['custo_fixo_pct'] ?? ($out['custo_fixo_pct'] ?? null)),
            'escopo'            => trim((string) (data_get($cont, 'escopo.objetivo') ?: data_get($cont, 'escopo.escopo_funcional') ?: '')) ?: null,
            'condicao_pagamento' => trim((string) (data_get($cont, 'prazo.condicao') ?: data_get($cont, 'prazo.pagamento_despesas') ?: '')) ?: null,
            'proposta_status'   => $p->status,
            'data_assinatura'   => optional($p->participants()->whereNotNull('signed_at')->max('signed_at'))
                ? \Illuminate\Support\Carbon::parse($p->participants()->whereNotNull('signed_at')->max('signed_at'))->format('d/m/Y H:i') : null,
        ], fn ($x) => $x !== null && $x !== '' && $x !== 0 && $x !== '0' && $x !== '0%' && $x !== '0,0%');
    }

    /** Responsáveis comerciais cadastrados (cadastro CRM › Responsáveis). */
    public function crmUsers(): JsonResponse
    {
        $users = \App\Models\User::where('is_crm_responsavel', true)
            ->orderBy('name')->get(['id', 'name', 'type']);
        return response()->json(['data' => $users]);
    }

    private function rules(bool $creating): array
    {
        return [
            'title'               => ($creating ? 'required' : 'sometimes') . '|string|max:180',
            'descricao'           => 'nullable|string', // o que o cliente pretende adquirir (obrigatória antes da proposta)
            // Item 1 (Fase 5): pipeline_id orientado por configuração (substitui tipo fixo).
            'pipeline_id'         => ($creating ? 'required' : 'sometimes') . '|exists:crm_pipelines,id',
            'tipo'                => 'nullable|string|max:40',
            'customer_id'         => ($creating ? 'required' : 'sometimes') . '|exists:customers,id',
            'customer_contact_id' => ($creating ? 'required' : 'nullable') . '|exists:customer_contacts,id',
            'lead_source_id'      => ($creating ? 'required' : 'nullable') . '|exists:crm_lead_sources,id',
            'responsavel_id'      => ($creating ? 'required' : 'nullable') . '|exists:users,id',
            // Item 1: toda oportunidade nasce com próxima ação + data.
            'proxima_acao'        => ($creating ? 'required' : 'nullable') . '|string|max:200',
            'proxima_acao_at'     => ($creating ? 'required' : 'nullable') . '|date',
            // pipeline_id/stage_id são DERIVADOS do tipo no servidor (não dependem da aba aberta).
            'stage_id'            => 'sometimes|exists:crm_pipeline_stages,id',
            'valor'               => 'nullable|numeric|min:0',
            'data_abertura'       => 'nullable|date',
            'previsao_fechamento' => 'nullable|date',
            'sla_dias'            => 'nullable|integer|min:0',
            'campaign_id'         => 'nullable|exists:crm_campaigns,id',
            'notas'               => 'nullable|string',
            // Enriquecimento do card (RD-like): qualificação + mapa de detalhes da negociação.
            'qualificacao'        => 'nullable|in:frio,morno,quente',
            'detalhes'            => 'nullable|array',
            // Previsibilidade: probabilidade manual (%) + motivo da parada.
            'probabilidade'       => 'nullable|integer|min:0|max:100',
            'motivo_parada'       => 'nullable|string|max:40',
            // Auditoria: justificativa de alteração de prob/valor/previsão (governança do forecast).
            'motivo_alteracao'    => 'nullable|string|max:200',
            // Categoria de forecast (Commit/Best Case/Pipeline/Omitido).
            'forecast_categoria'  => 'nullable|in:commit,best_case,pipeline,omitido',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));

        // Item 1 (Fase 5): pipeline escolhido por configuração (sem código fixo).
        CrmPipelineController::ensureSeeded();
        $pipeline = !empty($v['pipeline_id'])
            ? CrmPipeline::with('stages')->find($v['pipeline_id'])
            : (!empty($v['tipo']) ? CrmPipeline::where('code', $v['tipo'])->with('stages')->first() : null); // back-compat
        if (!$pipeline) {
            return response()->json(['message' => 'Pipeline é obrigatório.'], 422);
        }
        // Só aceita novas oportunidades em pipeline COMERCIAL, ATIVO e NÃO arquivado.
        if ($pipeline->tipo !== 'comercial' || !$pipeline->active || $pipeline->arquivado) {
            return response()->json(['message' => 'Este pipeline não aceita novas oportunidades (inativo/arquivado).', 'code' => 'PIPELINE_INDISPONIVEL'], 422);
        }
        $v['pipeline_id'] = $pipeline->id;
        $v['tipo'] = $pipeline->code; // referência (não-fixa)
        // Etapa inicial configurável (is_inicial); fallback p/ a 1ª por ordem.
        $v['stage_id'] = ($pipeline->stages->firstWhere('is_inicial', true) ?? $pipeline->stages->sortBy('ordem')->first())?->id;

        // Item 4: o contato principal deve pertencer à empresa selecionada.
        $contatoOk = \App\Models\CustomerContact::where('id', $v['customer_contact_id'])
            ->where('customer_id', $v['customer_id'])->exists();
        if (!$contatoOk) {
            return response()->json(['message' => 'O contato principal não pertence à empresa selecionada.'], 422);
        }

        $v['data_abertura'] = $v['data_abertura'] ?? now()->toDateString();
        $v['status'] = 'aberto';
        $v['created_by_id'] = auth()->id();
        // origem (texto) espelha o nome da origem cadastrada — compat/legado.
        $v['origem'] = \App\Models\CrmLeadSource::find($v['lead_source_id'])?->name;

        $o = CrmOpportunity::create($v);
        CrmOpportunityEvent::log($o->id, 'created', ['to_value' => $o->title]);

        // Funil Lead→Prospect→Oportunidade: abrir uma oportunidade É a qualificação.
        // Empresa única — promove o MESMO registro de lead para prospect (sem duplicar).
        $cust = Customer::find($v['customer_id']);
        if ($cust && $cust->crm_status === 'lead') {
            $cust->update(['crm_status' => 'prospect']);
            $prospectStage = CrmPipelineController::ensureQualificationSeeded()->load('stages')->stages->firstWhere('is_won', true);
            $cust->crmProfile()->updateOrCreate(['customer_id' => $cust->id], [
                'qualified_at' => $cust->crmProfile?->qualified_at ?? now(),
                'qualification_stage_id' => $prospectStage?->id,
                'lost_at' => null, 'lost_reason' => null,
            ]);
            \App\Models\CrmCustomerEvent::log($cust->id, 'qualified', 'Qualificado ao criar oportunidade');
            \App\Models\CrmCustomerEvent::log($cust->id, 'prospect', 'Convertido para Prospect');
        }

        // Fase 5 (homologação): criar a oportunidade JÁ É entrar na etapa inicial →
        // dispara as automações "ao_entrar" da etapa inicial (ex.: tarefa de 1º contato).
        $stageInicial = CrmPipelineStage::find($v['stage_id']);
        if ($stageInicial) {
            app(\App\Services\StageAutomationRunner::class)->runOnEnter($o->fresh(), $stageInicial);
        }

        return response()->json(['data' => $this->decorate($o->fresh())], 201);
    }

    public function update(Request $request, CrmOpportunity $opportunity): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        $oldStage = $opportunity->stage_id;
        $oldValor = (float) $opportunity->valor;
        $oldProb  = $opportunity->probabilidade; // override manual anterior (pode ser null)
        $oldPrev  = optional($opportunity->previsao_fechamento)->toDateString();
        $oldMotivoParada = $opportunity->motivo_parada;
        $motivo   = $v['motivo_alteracao'] ?? null;
        unset($v['motivo_alteracao']); // não é coluna

        // GOVERNANÇA: alterar a probabilidade manual exige justificativa.
        if (array_key_exists('probabilidade', $v) && $v['probabilidade'] !== null && (int) $v['probabilidade'] !== (int) ($oldProb ?? -1) && !$motivo) {
            return response()->json(['message' => 'Justifique a alteração da probabilidade (motivo obrigatório).'], 422);
        }

        // `detalhes` é MERGE (atualização parcial dos campos da negociação, não substitui o mapa inteiro).
        if (array_key_exists('detalhes', $v)) {
            $v['detalhes'] = array_filter(array_merge((array) ($opportunity->detalhes ?? []), (array) $v['detalhes']), fn ($x) => $x !== null && $x !== '');
        }
        // Carimba quando o motivo da parada é (des)marcado.
        if (array_key_exists('motivo_parada', $v)) $v['parada_em'] = $v['motivo_parada'] ? now() : null;
        $opportunity->update($v);

        // AUDITORIA do forecast (timeline): valor, probabilidade e previsão de fechamento.
        if (array_key_exists('valor', $v) && (float) $opportunity->valor !== $oldValor) {
            CrmOpportunityEvent::log($opportunity->id, 'valor_alterado', [
                'field' => 'valor', 'from_value' => number_format($oldValor, 2, '.', ''),
                'to_value' => number_format((float) $opportunity->valor, 2, '.', ''), 'meta' => ['motivo' => $motivo],
            ]);
        }
        if (array_key_exists('probabilidade', $v) && (int) ($opportunity->probabilidade ?? -1) !== (int) ($oldProb ?? -1)) {
            CrmOpportunityEvent::log($opportunity->id, 'probabilidade_alterada', [
                'field' => 'probabilidade', 'from_value' => $oldProb === null ? null : $oldProb . '%',
                'to_value' => $opportunity->probabilidade === null ? null : $opportunity->probabilidade . '%', 'meta' => ['motivo' => $motivo],
            ]);
        }
        if (array_key_exists('previsao_fechamento', $v) && optional($opportunity->previsao_fechamento)->toDateString() !== $oldPrev) {
            CrmOpportunityEvent::log($opportunity->id, 'previsao_alterada', [
                'field' => 'previsao_fechamento', 'from_value' => $oldPrev, 'to_value' => optional($opportunity->previsao_fechamento)->toDateString(), 'meta' => ['motivo' => $motivo],
            ]);
        }
        if (array_key_exists('motivo_parada', $v) && $opportunity->motivo_parada !== $oldMotivoParada) {
            CrmOpportunityEvent::log($opportunity->id, 'parada_alterada', [
                'field' => 'motivo_parada', 'from_value' => $oldMotivoParada, 'to_value' => $opportunity->motivo_parada,
                'meta' => ['motivo' => $opportunity->detalhes['motivo_parada_obs'] ?? null],
            ]);
        }
        if (array_key_exists('stage_id', $v) && (int) $v['stage_id'] !== (int) $oldStage) {
            $this->applyStage($opportunity, (int) $v['stage_id'], $oldStage, $request->input('motivo'));
        }
        return response()->json(['data' => $this->decorate($opportunity->fresh())]);
    }

    /** Move de etapa (kanban). */
    public function moveStage(Request $request, CrmOpportunity $opportunity): JsonResponse
    {
        $v = $request->validate([
            'stage_id'       => 'required|exists:crm_pipeline_stages,id',
            'motivo'         => 'nullable|string|max:160',
            'loss_reason_id' => 'nullable|exists:crm_loss_reasons,id',
        ]);
        $stage = CrmPipelineStage::find($v['stage_id']);

        // Fase 2 — regras de transição CONFIGURÁVEIS por etapa (substitui o hardcoded).
        $faltantes = $stage ? $this->missingStageRequirements($opportunity, $stage) : [];
        if ($faltantes) {
            return response()->json([
                'message' => "Para avançar para \"{$stage->name}\" é necessário: " . implode(', ', $faltantes) . '.',
                'code' => 'REGRA_ETAPA',
                'faltantes' => $faltantes,
            ], 422);
        }
        // Perda exige motivo (cadastro configurável) — intrínseco a etapas is_lost.
        if ($stage && $stage->is_lost && empty($v['loss_reason_id'])) {
            return response()->json([
                'message' => 'Informe o motivo da perda.',
                'code' => 'MOTIVO_PERDA_OBRIGATORIO',
            ], 422);
        }

        $old = $opportunity->stage_id;
        if ((int) $v['stage_id'] !== (int) $old) {
            $opportunity->stage_id = $v['stage_id'];
            $opportunity->save();
            $this->applyStage($opportunity, (int) $v['stage_id'], $old, $v['motivo'] ?? null, $v['loss_reason_id'] ?? null);
        }
        return response()->json(['data' => $this->decorate($opportunity->fresh())]);
    }

    /** Efeitos colaterais de mudança de etapa: ganho/perdido + status da empresa + evento. */
    /** Fase 2 — campos exigidos pela etapa (regras) que ainda não estão preenchidos. */
    private function missingStageRequirements(CrmOpportunity $o, CrmPipelineStage $stage): array
    {
        $regras = is_array($stage->regras) ? $stage->regras : [];
        if (!$regras) return [];
        $validadores = [
            // 'produto' descontinuado — trabalhamos com contratos/proposta, não catálogo de produtos.
            'valor'        => ['Valor',                fn () => (float) $o->valor > 0],
            'responsavel'  => ['Responsável comercial', fn () => !empty($o->responsavel_id)],
            'proxima_acao' => ['Próxima ação',         fn () => !empty($o->proxima_acao_at)],
            'contato'      => ['Contato principal',    fn () => !empty($o->customer_contact_id)],
            'descricao'    => ['Descrição da oportunidade', fn () => filled($o->descricao)],
            'proposta'     => ['Proposta',             fn () => \App\Models\CrmProposal::where('opportunity_id', $o->id)->exists()],
            'proposta_aprovada' => ['Proposta aprovada', fn () => \App\Models\CrmProposal::where('opportunity_id', $o->id)->where('status', 'aprovada')->exists()],
        ];
        $faltantes = [];
        foreach ($regras as $r) {
            if (isset($validadores[$r]) && !$validadores[$r][1]()) {
                $faltantes[] = $validadores[$r][0];
            }
        }
        return $faltantes;
    }

    private function applyStage(CrmOpportunity $o, int $newStageId, ?int $oldStageId, ?string $motivo, ?int $lossReasonId = null): void
    {
        $stage = CrmPipelineStage::find($newStageId);
        $from = $oldStageId ? optional(CrmPipelineStage::find($oldStageId))->name : null;
        CrmOpportunityEvent::log($o->id, 'stage_changed', ['field' => 'stage', 'from_value' => $from, 'to_value' => $stage?->name]);

        if ($stage?->is_won) {
            $o->update(['status' => 'ganho', 'fechamento_at' => now(), 'motivo' => $motivo]);
            CrmOpportunityEvent::log($o->id, 'won', ['to_value' => $motivo]);
            // Empresa única: avança status comercial (sem mexer em permissões).
            // Item 1 (Opção A): só promove a "cliente" se houver CNPJ; senão mantém prospect
            // (a oportunidade segue ganha; promover sem CNPJ violaria a regra cadastral).
            $c = Customer::find($o->customer_id);
            if ($c && in_array($c->crm_status, ['lead', 'prospect'], true)) {
                if (!empty($c->cgc)) {
                    $c->update(['crm_status' => 'cliente']);
                } else {
                    CrmOpportunityEvent::log($o->id, 'cliente_pendente_cnpj', ['to_value' => 'Ganha sem CNPJ — preencha para promover a cliente']);
                }
            }
            // Oportunidade GANHA → marca a proposta vencedora como CONVERTIDA, para a Gestão de Propostas
            // refletir "Convertida" assim que o card vai para Ganho no Pipeline (não depende de gerar o contrato).
            $propVencedora = \App\Models\CrmProposal::where('opportunity_id', $o->id)->whereNull('deleted_at')
                ->whereIn('status', ['assinada', 'liberada', 'aguardando_assinatura', 'enviada', 'aprovada'])
                ->orderByDesc('versao')->orderByDesc('id')->first();
            if ($propVencedora) $propVencedora->update(['status' => 'convertida']);
        } elseif ($stage?->is_lost) {
            $o->update(['status' => 'perdido', 'fechamento_at' => now(), 'motivo' => $motivo, 'loss_reason_id' => $lossReasonId]);
            $motivoNome = $lossReasonId ? optional(\App\Models\CrmLossReason::find($lossReasonId))->name : $motivo;
            CrmOpportunityEvent::log($o->id, 'lost', ['to_value' => $motivoNome]);
        } elseif ($o->status !== 'aberto') {
            // Reabriu numa etapa intermediária.
            $o->update(['status' => 'aberto', 'fechamento_at' => null]);
        }

        // Fase 3 — motor de automações ao ENTRAR na etapa (não quebra a mudança se falhar).
        if ($stage) {
            app(\App\Services\StageAutomationRunner::class)->runOnEnter($o->fresh(), $stage);
        }
    }

    // ── Produtos vinculados (Item 3) ─────────────────────────────────────────
    /** Recalcula o valor da oportunidade = Σ(quantidade × valor) dos produtos. */
    private function recomputeValor(CrmOpportunity $o): void
    {
        $total = $o->products()->get()->sum(fn ($p) => (float) $p->pivot->quantidade * (float) $p->pivot->valor);
        if ($o->products()->exists()) {
            $o->update(['valor' => round($total, 2)]);
        }
    }

    /** Vincula (ou atualiza) um produto à oportunidade. */
    public function addProduct(Request $request, CrmOpportunity $opportunity): JsonResponse
    {
        $v = $request->validate([
            'crm_product_id' => 'required|exists:crm_products,id',
            'quantidade'     => 'nullable|numeric|min:0',
            'valor'          => 'nullable|numeric|min:0',
        ]);
        $produto = \App\Models\CrmProduct::find($v['crm_product_id']);
        $pivot = ['quantidade' => $v['quantidade'] ?? 1, 'valor' => $v['valor'] ?? $produto->valor ?? 0];

        if ($opportunity->products()->where('crm_product_id', $v['crm_product_id'])->exists()) {
            $opportunity->products()->updateExistingPivot($v['crm_product_id'], $pivot);
        } else {
            $opportunity->products()->attach($v['crm_product_id'], $pivot);
        }
        $this->recomputeValor($opportunity);
        return $this->show($opportunity->fresh());
    }

    /** Atualiza quantidade/valor de um produto já vinculado. */
    public function updateProduct(Request $request, CrmOpportunity $opportunity, int $product): JsonResponse
    {
        $v = $request->validate([
            'quantidade' => 'nullable|numeric|min:0',
            'valor'      => 'nullable|numeric|min:0',
        ]);
        abort_unless($opportunity->products()->where('crm_product_id', $product)->exists(), 404);
        $opportunity->products()->updateExistingPivot($product, array_filter([
            'quantidade' => $v['quantidade'] ?? null,
            'valor'      => $v['valor'] ?? null,
        ], fn ($x) => $x !== null));
        $this->recomputeValor($opportunity);
        return $this->show($opportunity->fresh());
    }

    /** Remove um produto da oportunidade. */
    public function removeProduct(CrmOpportunity $opportunity, int $product): JsonResponse
    {
        $opportunity->products()->detach($product);
        $this->recomputeValor($opportunity);
        return $this->show($opportunity->fresh());
    }

    // ===== ANEXOS da oportunidade (camada Attachment, entity CRM_OPPORTUNITY) =====
    public function attachments(Request $request, CrmOpportunity $opportunity, AttachmentService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->listFor('CRM_OPPORTUNITY', $opportunity->id, $request->user())->values()]);
    }

    public function uploadAttachment(Request $request, CrmOpportunity $opportunity, AttachmentService $svc): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:15360']);
        $att = $svc->store($request->user(), [
            'entity_type' => 'CRM_OPPORTUNITY', 'entity_id' => $opportunity->id,
            'category' => 'attachment', 'file' => $request->file('file'),
        ], $request);
        return response()->json(['data' => $att], 201);
    }

    public function downloadAttachment(Request $request, CrmOpportunity $opportunity, Attachment $attachment, AttachmentService $svc)
    {
        abort_unless($attachment->entity_type === 'CRM_OPPORTUNITY' && (int) $attachment->entity_id === $opportunity->id, 404);
        return $svc->downloadStream($attachment, $request->user(), $request);
    }

    public function deleteAttachment(Request $request, CrmOpportunity $opportunity, Attachment $attachment, AttachmentService $svc): JsonResponse
    {
        abort_unless($attachment->entity_type === 'CRM_OPPORTUNITY' && (int) $attachment->entity_id === $opportunity->id, 404);
        $svc->softDelete($attachment, $request->user(), $request);
        return response()->json(null, 204);
    }
}
