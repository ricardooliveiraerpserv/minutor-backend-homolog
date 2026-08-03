<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerCrmProfile;
use App\Models\CrmLeadSource;
use App\Models\CrmCustomerEvent;
use App\Models\CrmContactType;
use App\Models\CrmTask;
use App\Models\CrmPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRM — CAMADA DE LEADS sobre a EMPRESA ÚNICA (customers).
 * Lead = customer com crm_status='lead'. Captação/qualificação ficam no perfil 1:1
 * (customer_crm_profiles) e no funil de qualificação (crm_pipelines code=qualificacao).
 * NÃO cria tabela paralela de leads/empresa. NÃO altera oportunidades/propostas/contratos.
 */
class CrmLeadController extends Controller
{
    private function pipeline(): CrmPipeline
    {
        return CrmPipelineController::ensureQualificationSeeded()->load('stages');
    }

    /**
     * DTO de um lead. A PRÓXIMA AÇÃO vem da CrmTask aberta (fonte da verdade), não mais do perfil.
     * $ctx: ['followups'=>int, 'openTask'=>CrmTask|null, 'tiposNome'=>Collection].
     */
    private function decorate(Customer $c, array $ctx = []): array
    {
        $p = $c->crmProfile;
        $contact = $c->contacts->first();

        // Próxima ação = CrmTask aberta mais próxima (fallback: campo legado do perfil).
        $openTask = array_key_exists('openTask', $ctx) ? $ctx['openTask']
            : \App\Models\CrmTask::where('customer_id', $c->id)->whereNull('concluida_at')->orderBy('data')->first();
        $tiposNome = $ctx['tiposNome'] ?? \App\Models\CrmContactType::pluck('nome', 'slug');
        $proxAcao = $openTask ? ($openTask->titulo ?: ($tiposNome[$openTask->tipo] ?? $openTask->tipo)) : $p?->proxima_acao;
        $proxAt = $openTask?->data ? $openTask->data->toDateString() : $p?->proxima_acao_at?->toDateString();
        $proxAtData = $openTask?->data ?: $p?->proxima_acao_at;
        $proxAtrasada = $proxAtData && !$p?->lost_at && $proxAtData->copy()->endOfDay()->isPast();
        $temProxFutura = $proxAtData && !$p?->lost_at && !$proxAtData->copy()->endOfDay()->isPast();
        $semProxima = !$openTask && !$p?->proxima_acao_at && !$p?->lost_at;

        // Sinais comerciais
        $diasSem = $p?->ultima_interacao_at ? (int) $p->ultima_interacao_at->startOfDay()->diffInDays(now()->startOfDay()) : null;
        $ordem = $p?->qualification_stage_id
            ? (int) (optional($this->pipeline()->stages->firstWhere('id', $p->qualification_stage_id))->ordem ?? 1) : 1;
        $followups = $ctx['followups'] ?? \App\Models\CrmTask::where('customer_id', $c->id)->whereNotNull('concluida_at')->count();
        $temperatura = $p?->lost_at ? 'frio' : $this->temperatura($diasSem, $temProxFutura, $ordem > 1, $followups);

        $vp = (float) ($p?->valor_potencial ?? 0);
        $faixa = $vp <= 0 ? null : ($vp <= 5000 ? 'ate_5k' : ($vp <= 20000 ? '5k_20k' : 'acima_20k'));

        // SLA de PRIMEIRO CONTATO (meta 24h da criação do lead à 1ª interação concluída).
        $primeiroContato = array_key_exists('primeiroContato', $ctx) ? $ctx['primeiroContato']
            : \App\Models\CrmTask::where('customer_id', $c->id)->whereNotNull('concluida_at')->min('concluida_at');
        $contatado = $primeiroContato !== null;
        $base1c = $p?->lead_created_at ?? $c->created_at;
        $horas1c = $base1c ? (int) \Illuminate\Support\Carbon::parse($base1c)->diffInHours($contatado ? \Illuminate\Support\Carbon::parse($primeiroContato) : now()) : null;
        $sla1cEstourado = !$p?->lost_at && $horas1c !== null && $horas1c > 24;
        $semResponsavel = $c->executive_id === null;

        return [
            'customer_id'         => $c->id,
            'empresa'             => $c->name,
            'company_name'        => $c->company_name,
            'crm_status'          => $c->crm_status,
            'stage_id'            => $p?->qualification_stage_id,
            'lead_source'         => $p?->leadSource?->only(['id', 'name']),
            'executive'           => $c->executive?->only(['id', 'name']),
            'observacoes'         => $p?->observacoes,
            // Próxima ação (derivada da CrmTask aberta)
            'proxima_task_id'     => $openTask?->id,
            'proxima_acao'        => $proxAcao,
            'proxima_tipo'        => $openTask?->tipo,
            'proxima_descricao'   => $openTask?->titulo,
            'proxima_acao_at'     => $proxAt,
            'proxima_objetivo'    => $openTask?->objetivo,
            'proxima_responsavel' => $openTask?->responsavel?->only(['id', 'name']) ?? $c->executive?->only(['id', 'name']),
            'proxima_acao_atrasada' => (bool) $proxAtrasada,
            'ultima_interacao_at' => $p?->ultima_interacao_at,
            'dias_sem_interacao'  => $diasSem,
            'temperatura'         => $temperatura,
            'valor_potencial'     => $vp > 0 ? $vp : null,
            'faixa_potencial'     => $faixa,
            'previsao_fechamento' => $p?->previsao_fechamento?->toDateString(),
            'previsao_competencia' => $p?->previsao_competencia,
            'previsao_label'      => $this->previsaoLabel($p),
            'followups_count'     => $followups,
            'lead_created_at'     => $p?->lead_created_at,
            'lost_at'             => $p?->lost_at,
            'lost_reason'         => $p?->lost_reason,
            'sem_proxima_acao'    => $semProxima,
            'sem_responsavel'     => $semResponsavel,
            'primeiro_contato_horas' => $horas1c,
            'primeiro_contato_feito' => $contatado,
            'sla_primeiro_contato_estourado' => (bool) $sla1cEstourado,
            'contato'             => $contact ? [
                'id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email,
                'phone' => $contact->phone, 'whatsapp' => $contact->whatsapp,
            ] : null,
        ];
    }

    /** Rótulo da previsão de fechamento: competência (Jul/2026) tem prioridade sobre data exata. */
    private function previsaoLabel(?CustomerCrmProfile $p): ?string
    {
        if ($p?->previsao_competencia) {
            [$y, $m] = array_pad(explode('-', $p->previsao_competencia), 2, null);
            $meses = [1 => 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
            return ($meses[(int) $m] ?? $m) . '/' . $y;
        }
        return $p?->previsao_fechamento?->format('d/m/Y');
    }

    /**
     * Temperatura AUTOMÁTICA do lead por heurística de engajamento:
     *  + interação recente, + próxima ação agendada, + avanço no funil, + histórico de follow-ups;
     *  − dias parado / nunca interagido / sem próxima ação. quente ≥3 · morno 1–2 · frio ≤0.
     */
    private function temperatura(?int $diasSem, bool $temProxFutura, bool $avancou, int $followups): string
    {
        $score = 0;
        if ($diasSem === null) $score -= 1;
        elseif ($diasSem <= 3) $score += 2;
        elseif ($diasSem <= 10) $score += 1;
        else $score -= 1;
        $score += $temProxFutura ? 2 : -1;
        if ($avancou) $score += 1;
        if ($followups >= 3) $score += 1;
        return $score >= 3 ? 'quente' : ($score >= 1 ? 'morno' : 'frio');
    }

    /** Kanban de leads: funil de qualificação + leads agrupáveis por etapa. */
    public function index(): JsonResponse
    {
        $pipe = $this->pipeline();
        // Visibilidade: Leads agora é um pipeline (qualificação) — só admin ou liberados.
        if (!$pipe->canBeSeenBy(request()->user())) {
            return response()->json(['message' => 'Sem acesso ao pipeline de Leads.'], 403);
        }
        $customers = Customer::where('crm_status', 'lead')
            ->with(['crmProfile.leadSource', 'executive:id,name', 'contacts'])
            ->orderByDesc('id')->get();
        $ids = $customers->pluck('id');
        $tiposNome = CrmContactType::pluck('nome', 'slug');
        // Follow-ups concluídos por lead (lote).
        $fupCounts = CrmTask::whereIn('customer_id', $ids)->whereNotNull('concluida_at')
            ->selectRaw('customer_id, count(*) as c')->groupBy('customer_id')->pluck('c', 'customer_id');
        // Próxima tarefa ABERTA por lead (lote) — fonte da verdade da próxima ação.
        $openByCustomer = CrmTask::whereIn('customer_id', $ids)->whereNull('concluida_at')
            ->with('responsavel:id,name')->orderBy('data')->get()->groupBy('customer_id');
        // Primeiro contato (min concluida_at) por lead — base do SLA de 1º contato.
        $primeiroContato = CrmTask::whereIn('customer_id', $ids)->whereNotNull('concluida_at')
            ->selectRaw('customer_id, min(concluida_at) as pc')->groupBy('customer_id')->pluck('pc', 'customer_id');
        $leads = $customers->map(fn ($c) => $this->decorate($c, [
            'followups' => (int) ($fupCounts[$c->id] ?? 0),
            'openTask'  => $openByCustomer->get($c->id)?->first(),
            'primeiroContato' => $primeiroContato[$c->id] ?? null,
            'tiposNome' => $tiposNome,
        ]));

        return response()->json(['data' => [
            'pipeline' => ['id' => $pipe->id, 'name' => $pipe->name,
                'stages' => $pipe->stages->map(fn ($s) => $s->only(['id', 'name', 'ordem', 'is_won', 'is_lost']))->values()],
            'leads'    => $leads->values(),
        ]]);
    }

    /** Cadastro rápido de lead (campos mínimos). */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'empresa'        => 'required|string|max:160',
            'cnpj'           => 'nullable|string|max:32',
            'contato'        => 'nullable|string|max:120',
            'telefone'       => 'nullable|string|max:40',
            'whatsapp'       => 'nullable|string|max:40',
            'email'          => 'nullable|email|max:160',
            'lead_source_id' => 'required|exists:crm_lead_sources,id', // Origem obrigatória
            'executive_id'   => 'nullable|exists:users,id',
            'observacoes'    => 'nullable|string',
            'valor_potencial' => 'nullable|numeric|min:0',
        ]);

        $pipe = $this->pipeline();
        $primeira = $pipe->stages->sortBy('ordem')->first();

        $customer = DB::transaction(function () use ($v, $primeira) {
            // Item 1 (Opção A): lead/prospect podem existir SEM CNPJ (cgc nullable).
            $customer = Customer::create([
                'name'         => $v['empresa'],
                'company_name' => $v['empresa'],
                'cgc'          => $v['cnpj'] ?? null,
                'active'       => false,           // lead ainda não é cliente operacional
                'crm_status'   => 'lead',
                'executive_id' => $v['executive_id'] ?? null,
            ]);

            $profile = $customer->crmProfile()->create([
                'lead_source_id'         => $v['lead_source_id'] ?? null,
                'observacoes'            => $v['observacoes'] ?? null,
                'valor_potencial'        => $v['valor_potencial'] ?? null,
                'lead_created_at'        => now(),
                'qualification_stage_id' => $primeira?->id,
            ]);

            // Integração CRM ↔ Investimento Comercial: cria o lead-projeto (custo de
            // prospecção) sob o Investimento Comercial da ERPSERV e vincula ao perfil.
            // Roda com privilégio de sistema (independe da permissão do usuário do CRM).
            $leadProjeto = app(\App\Services\InvestimentoComercialService::class)->criarLeadProjeto($customer);
            if ($leadProjeto) {
                $profile->update(['investimento_project_id' => $leadProjeto->id]);
            }

            if (!empty($v['contato']) || !empty($v['email']) || !empty($v['telefone']) || !empty($v['whatsapp'])) {
                $customer->contacts()->create([
                    'name'     => $v['contato'] ?? $v['empresa'],
                    'email'    => $v['email'] ?? null,
                    'phone'    => $v['telefone'] ?? null,
                    'whatsapp' => $v['whatsapp'] ?? null,
                ]);
            }

            CrmCustomerEvent::log($customer->id, 'lead_created', 'Lead criado');
            return $customer;
        });

        return response()->json(['data' => $this->decorate($customer->fresh(['crmProfile.leadSource', 'executive', 'contacts']))], 201);
    }

    /** Edita dados do lead + próxima ação; opcionalmente registra uma interação. */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $v = $request->validate([
            'empresa'         => 'nullable|string|max:160',
            'lead_source_id'  => 'nullable|exists:crm_lead_sources,id',
            'executive_id'    => 'nullable|exists:users,id',
            'observacoes'     => 'nullable|string',
            'valor_potencial' => 'nullable|numeric|min:0',
            'previsao_fechamento' => 'nullable|date',
            'previsao_competencia' => 'nullable|string|max:7',
            'proxima_acao'    => 'nullable|string|max:200',
            'proxima_acao_at' => 'nullable|date',
            'interacao'       => 'nullable|string|max:200',  // registra última interação na timeline
            'contato'         => 'nullable|array',
        ]);

        if (array_key_exists('empresa', $v) && $v['empresa']) {
            $customer->name = $v['empresa'];
        }
        if (array_key_exists('executive_id', $v)) $customer->executive_id = $v['executive_id'];
        $customer->save();

        $profileData = array_filter([
            'lead_source_id'  => $v['lead_source_id'] ?? null,
            'observacoes'     => $v['observacoes'] ?? null,
            'valor_potencial' => $v['valor_potencial'] ?? null,
            'proxima_acao'    => $v['proxima_acao'] ?? null,
            'proxima_acao_at' => $v['proxima_acao_at'] ?? null,
        ], fn ($x) => $x !== null);
        if (array_key_exists('proxima_acao_at', $v)) $profileData['proxima_acao_at'] = $v['proxima_acao_at']; // permite limpar
        if (array_key_exists('valor_potencial', $v)) $profileData['valor_potencial'] = $v['valor_potencial']; // permite limpar
        if (array_key_exists('proxima_acao', $v)) $profileData['proxima_acao'] = $v['proxima_acao'];
        if (array_key_exists('previsao_fechamento', $v)) $profileData['previsao_fechamento'] = $v['previsao_fechamento'];
        if (array_key_exists('previsao_competencia', $v)) $profileData['previsao_competencia'] = $v['previsao_competencia'];

        if (!empty($v['interacao'])) {
            $profileData['ultima_interacao_at'] = now();
        }
        if ($profileData) {
            $customer->crmProfile()->updateOrCreate(['customer_id' => $customer->id], $profileData);
        }
        if (!empty($v['interacao'])) {
            CrmCustomerEvent::log($customer->id, 'interaction', $v['interacao']);
        }

        if (!empty($v['contato']) && is_array($v['contato'])) {
            $contact = $customer->contacts()->first();
            $data = array_intersect_key($v['contato'], array_flip(['name', 'email', 'phone', 'whatsapp']));
            $contact ? $contact->update($data) : $customer->contacts()->create($data + ['name' => $customer->name]);
        }

        return response()->json(['data' => $this->decorate($customer->fresh(['crmProfile.leadSource', 'executive', 'contacts']))]);
    }

    /** Move o lead entre etapas do funil de qualificação (drag no kanban). */
    public function moveStage(Request $request, Customer $customer): JsonResponse
    {
        $v = $request->validate([
            'stage_id'    => 'required|exists:crm_pipeline_stages,id',
            'lost_reason' => 'nullable|string|max:200',
        ]);
        $stage = \App\Models\CrmPipelineStage::findOrFail($v['stage_id']);

        // Etapa "Prospect" (is_won) só pela ação de conversão (preenche firmográfico).
        if ($stage->is_won) {
            return response()->json(['message' => 'Use "Converter para Prospect" para qualificar este lead.'], 422);
        }

        $profile = $customer->crmProfile()->firstOrCreate(['customer_id' => $customer->id]);
        $profile->qualification_stage_id = $stage->id;
        if ($stage->is_lost) {
            $profile->lost_at = now();
            $profile->lost_reason = $v['lost_reason'] ?? null;
            CrmCustomerEvent::log($customer->id, 'lost', 'Lead perdido' . (!empty($v['lost_reason']) ? " — {$v['lost_reason']}" : ''));
        } else {
            $profile->lost_at = null;
            $profile->lost_reason = null;
            CrmCustomerEvent::log($customer->id, 'stage_changed', $stage->name);
        }
        $profile->save();

        return response()->json(['data' => $this->decorate($customer->fresh(['crmProfile.leadSource', 'executive', 'contacts']))]);
    }

    /** QUALIFICAÇÃO: Lead → Prospect (preenche firmográfico e muda crm_status). */
    public function convertToProspect(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->crm_status !== 'lead') {
            return response()->json(['message' => 'Apenas leads podem ser qualificados para prospect.'], 422);
        }
        // GOVERNANÇA: lead sem responsável não avança (accountability).
        if ($customer->executive_id === null) {
            return response()->json(['message' => 'Defina um responsável antes de qualificar o lead para Prospect.'], 422);
        }
        $v = $request->validate([
            'segment'              => 'nullable|string|max:100',
            'erp_atual'            => 'nullable|string|max:120',
            'porte'                => 'nullable|string|max:30',
            'faturamento_estimado' => 'nullable|numeric|min:0',
            'num_funcionarios'     => 'nullable|integer|min:0',
            'region'               => 'nullable|string|max:100',
        ]);

        $pipe = $this->pipeline();
        $prospectStage = $pipe->stages->firstWhere('is_won', true);

        DB::transaction(function () use ($customer, $v, $prospectStage) {
            $customer->crm_status = 'prospect';
            $customer->save();

            $customer->crmProfile()->updateOrCreate(['customer_id' => $customer->id], array_merge(
                array_filter($v, fn ($x) => $x !== null && $x !== ''),
                ['qualified_at' => now(), 'lost_at' => null, 'lost_reason' => null,
                 'qualification_stage_id' => $prospectStage?->id]
            ));

            CrmCustomerEvent::log($customer->id, 'qualified', 'Lead qualificado');
            CrmCustomerEvent::log($customer->id, 'prospect', 'Convertido para Prospect');
        });

        return response()->json(['data' => [
            'customer_id' => $customer->id,
            'crm_status'  => 'prospect',
            'message'     => 'Lead qualificado para Prospect.',
        ]]);
    }

    /** Histórico de FOLLOW-UPS do lead — timeline (mais recente primeiro). Somente inclusão. */
    public function followups(Customer $customer): JsonResponse
    {
        $tiposNome = \App\Models\CrmContactType::pluck('nome', 'slug');
        $tasks = \App\Models\CrmTask::where('customer_id', $customer->id)
            ->orderByDesc('data')->orderByDesc('id')->get();
        $usuarios = \App\Models\User::whereIn('id', $tasks->pluck('created_by_id')->filter()->unique())->pluck('name', 'id');
        $rows = $tasks->map(fn ($t) => [
            'id'           => $t->id,
            'tipo'         => $tiposNome[$t->tipo] ?? $t->tipo,
            'titulo'       => $t->titulo,
            'data'         => optional($t->data)->toIso8601String(),
            'concluida_at' => optional($t->concluida_at)->toIso8601String(),
            'usuario'      => $usuarios[$t->created_by_id] ?? null,
        ]);
        return response()->json(['data' => $rows]);
    }

    /**
     * FLUXO UNIFICADO: registra um FOLLOW-UP (interação feita) e, em UM passo, já cria a PRÓXIMA AÇÃO
     * como CrmTask aberta (fonte da verdade). `concluir_proxima` fecha a tarefa aberta anterior.
     */
    public function addFollowup(Request $request, Customer $customer): JsonResponse
    {
        $v = $request->validate([
            'tipo'               => 'required|string|max:60',
            'descricao'          => 'nullable|string|max:1000',
            'data'               => 'nullable|date',
            'concluir_proxima'   => 'nullable|boolean',
            'proxima'            => 'nullable|array',
            'proxima.tipo'       => 'nullable|string|max:60',
            'proxima.descricao'  => 'nullable|string|max:200',
            'proxima.objetivo'   => 'nullable|string|max:200',
            'proxima.data'       => 'nullable|date',
            'proxima.responsavel_id' => 'nullable|exists:users,id',
        ]);
        DB::transaction(function () use ($v, $customer) {
            // 1) Follow-up = interação já realizada (CrmTask concluída)
            CrmTask::create([
                'customer_id'   => $customer->id,
                'tipo'          => $v['tipo'],
                'titulo'        => $v['descricao'] ?? null,
                'data'          => $v['data'] ?? now(),
                'concluida_at'  => now(),
                'created_by_id' => auth()->id(),
            ]);
            // 2) Conclui a próxima ação anterior (tarefa aberta), se solicitado
            if (!empty($v['concluir_proxima'])) {
                CrmTask::where('customer_id', $customer->id)->whereNull('concluida_at')->update(['concluida_at' => now()]);
            }
            // 3) Próximo passo → nova PRÓXIMA AÇÃO (CrmTask aberta)
            $prox = $v['proxima'] ?? null;
            if ($prox && !empty($prox['tipo'])) {
                CrmTask::create([
                    'customer_id'    => $customer->id,
                    'tipo'           => $prox['tipo'],
                    'titulo'         => $prox['descricao'] ?? null,
                    'objetivo'       => $prox['objetivo'] ?? null,
                    'data'           => $prox['data'] ?? null,
                    'responsavel_id' => $prox['responsavel_id'] ?? $customer->executive_id,
                    'created_by_id'  => auth()->id(),
                ]);
            }
            $profile = $customer->crmProfile()->firstOrCreate(['customer_id' => $customer->id]);
            $profile->ultima_interacao_at = now();
            $profile->save();
            $tipoNome = CrmContactType::where('slug', $v['tipo'])->value('nome') ?? $v['tipo'];
            CrmCustomerEvent::log($customer->id, 'interaction', trim($tipoNome . ' — ' . ($v['descricao'] ?? '')));
        });
        return response()->json(['data' => $this->decorate($customer->fresh(['crmProfile.leadSource', 'executive', 'contacts']))], 201);
    }

    /** Cria/atualiza a PRÓXIMA AÇÃO do lead como CrmTask aberta (sem registrar follow-up). */
    public function setProximaAcao(Request $request, Customer $customer): JsonResponse
    {
        $v = $request->validate([
            'task_id'        => 'nullable|integer',
            'tipo'           => 'required|string|max:60',
            'descricao'      => 'nullable|string|max:200',
            'objetivo'       => 'nullable|string|max:200',
            'data'           => 'nullable|date',
            'responsavel_id' => 'nullable|exists:users,id',
        ]);
        $attrs = [
            'tipo' => $v['tipo'], 'titulo' => $v['descricao'] ?? null, 'objetivo' => $v['objetivo'] ?? null,
            'data' => $v['data'] ?? null, 'responsavel_id' => $v['responsavel_id'] ?? $customer->executive_id,
        ];
        if (!empty($v['task_id'])) {
            $task = CrmTask::where('customer_id', $customer->id)->whereKey($v['task_id'])->firstOrFail();
            $task->update($attrs);
        } else {
            CrmTask::create($attrs + ['customer_id' => $customer->id, 'created_by_id' => auth()->id()]);
        }
        return response()->json(['data' => $this->decorate($customer->fresh(['crmProfile.leadSource', 'executive', 'contacts']))]);
    }

    /** SAÚDE DO LEAD — diagnóstico rápido (temperatura, risco, motivo, forecast). */
    public function health(Customer $customer): JsonResponse
    {
        $d = $this->decorate($customer->load(['crmProfile.leadSource', 'executive', 'contacts']));
        $dias = $d['dias_sem_interacao'];
        $temp = $d['temperatura'];
        $temProx = $d['proxima_acao'] && !$d['proxima_acao_atrasada'];

        $risco = 'baixo'; $motivos = [];
        if ($dias === null) { $motivos[] = 'nunca interagido'; $risco = 'alto'; }
        elseif ($dias > 10) { $motivos[] = "parado há {$dias} dias"; $risco = 'alto'; }
        elseif ($dias > 3) { $motivos[] = "última interação há {$dias} dias"; $risco = 'medio'; }
        if ($d['sem_proxima_acao']) { $motivos[] = 'sem próxima ação'; $risco = $risco === 'alto' ? 'alto' : 'medio'; }
        elseif ($d['proxima_acao_atrasada']) { $motivos[] = 'próxima ação atrasada'; $risco = $risco === 'alto' ? 'alto' : 'medio'; }
        if (!$d['previsao_label']) $motivos[] = 'sem previsão de fechamento';

        $diag = $temp === 'quente'
            ? 'Lead quente. ' . ($temProx ? 'Interagiu recentemente e tem próxima ação definida' . ($d['previsao_label'] ? ", com fechamento previsto para {$d['previsao_label']}." : '.') : 'Engajado, mas defina o próximo passo.')
            : ($risco === 'alto'
                ? 'Lead em risco. ' . ucfirst(implode(', ', $motivos)) . '.'
                : 'Lead ' . ($temp === 'morno' ? 'morno' : 'frio') . '. ' . (count($motivos) ? ucfirst(implode(', ', $motivos)) . '.' : 'Mantenha a cadência de contato.'));

        return response()->json(['data' => [
            'temperatura'        => $temp,
            'dias_sem_interacao' => $dias,
            'proxima_acao'       => $d['proxima_acao'],
            'proxima_objetivo'   => $d['proxima_objetivo'],
            'proxima_acao_atrasada' => $d['proxima_acao_atrasada'],
            'followups_count'    => $d['followups_count'],
            'valor_potencial'    => $d['valor_potencial'],
            'previsao_label'     => $d['previsao_label'],
            'risco'              => $risco,
            'motivos'            => $motivos,
            'diagnostico'        => $diag,
        ]]);
    }

    // ── Origens de lead (cadastro configurável) ──────────────────────────────
    public function sourcesIndex(): JsonResponse
    {
        return response()->json(['data' => CrmLeadSource::orderBy('ordem')->orderBy('name')->get()]);
    }

    public function sourcesStore(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name'  => 'required|string|max:80|unique:crm_lead_sources,name',
            'ordem' => 'nullable|integer',
        ]);
        return response()->json(['data' => CrmLeadSource::create($v + ['active' => true])], 201);
    }

    public function sourcesUpdate(Request $request, CrmLeadSource $source): JsonResponse
    {
        $v = $request->validate([
            'name'   => 'sometimes|string|max:80|unique:crm_lead_sources,name,' . $source->id,
            'ordem'  => 'nullable|integer',
            'active' => 'boolean',
        ]);
        $source->update($v);
        return response()->json(['data' => $source]);
    }
}
