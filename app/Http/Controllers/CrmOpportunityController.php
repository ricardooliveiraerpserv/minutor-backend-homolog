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
        $prob = $o->relationLoaded('stage') ? (int) ($o->stage?->probabilidade ?? 0) : 0;
        $aberto = $o->status === 'aberto';
        $diasSemInteracao = $o->ultima_interacao_at ? (int) $o->ultima_interacao_at->diffInDays(now()) : null;
        return array_merge($o->toArray(), [
            'sem_proxima_acao'      => $aberto && $o->proxima_acao_at === null,
            'proxima_acao_vencida'  => $aberto && $o->proxima_acao_at !== null && $o->proxima_acao_at->isPast(),
            'dias_sem_interacao'    => $diasSemInteracao,
            'sem_interacao_7'       => $aberto && ($o->ultima_interacao_at === null || $diasSemInteracao >= 7),
            'probabilidade'         => $prob,
            'forecast'              => round((float) $o->valor * $prob / 100, 2),
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

        $byStage = $opps->groupBy('stage_id');
        $cols = $stages->map(function ($s) use ($byStage, $diasNaEtapa) {
            $lista = $byStage[$s->id] ?? collect();
            $abertas = $lista->where('status', 'aberto');
            $prob = (int) ($s->probabilidade ?? 0);
            $valor = (float) $lista->sum('valor');
            $diasArr = $abertas->map($diasNaEtapa);
            return [
                'stage'              => $s,
                'opportunities'      => $lista->map(fn ($o) => array_merge($this->decorate($o), ['dias_na_etapa' => $diasNaEtapa($o)]))->values(),
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
        return response()->json(['data' => $this->filtered($request)->get()->map(fn ($o) => $this->decorate($o))]);
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
        $opportunity->load(['customer:id,name', 'pipeline:id,name', 'stage', 'responsavel:id,name',
            'leadSource:id,name', 'contato:id,name,email,phone,whatsapp', 'lossReason:id,name',
            'products', 'tasks.responsavel:id,name', 'events.triggeredBy:id,name', 'contract:id,status']);
        return response()->json(['data' => $this->decorate($opportunity)]);
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
        $opportunity->update($v);
        if (array_key_exists('valor', $v) && (float) $opportunity->valor !== $oldValor) {
            CrmOpportunityEvent::log($opportunity->id, 'valor_alterado', [
                'field'      => 'valor',
                'from_value' => number_format($oldValor, 2, '.', ''),
                'to_value'   => number_format((float) $opportunity->valor, 2, '.', ''),
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
