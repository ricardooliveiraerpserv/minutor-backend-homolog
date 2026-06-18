<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CrmLeadSource;
use App\Models\CrmCustomerEvent;
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

    /** DTO de um lead (empresa em status lead) para o kanban de qualificação. */
    private function decorate(Customer $c): array
    {
        $p = $c->crmProfile;
        $contact = $c->contacts->first();
        $semProxima = $p && !$p->proxima_acao_at && !$p->lost_at;
        return [
            'customer_id'         => $c->id,
            'empresa'             => $c->name,
            'company_name'        => $c->company_name,
            'crm_status'          => $c->crm_status,
            'stage_id'            => $p?->qualification_stage_id,
            'lead_source'         => $p?->leadSource?->only(['id', 'name']),
            'executive'           => $c->executive?->only(['id', 'name']),
            'observacoes'         => $p?->observacoes,
            'proxima_acao'        => $p?->proxima_acao,
            'proxima_acao_at'     => $p?->proxima_acao_at?->toDateString(),
            'ultima_interacao_at' => $p?->ultima_interacao_at,
            'lead_created_at'     => $p?->lead_created_at,
            'lost_at'             => $p?->lost_at,
            'lost_reason'         => $p?->lost_reason,
            'sem_proxima_acao'    => $semProxima,
            'contato'             => $contact ? [
                'id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email,
                'phone' => $contact->phone, 'whatsapp' => $contact->whatsapp,
            ] : null,
        ];
    }

    /** Kanban de leads: funil de qualificação + leads agrupáveis por etapa. */
    public function index(): JsonResponse
    {
        $pipe = $this->pipeline();
        $leads = Customer::where('crm_status', 'lead')
            ->with(['crmProfile.leadSource', 'executive:id,name', 'contacts'])
            ->orderByDesc('id')->get()
            ->map(fn ($c) => $this->decorate($c));

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
            'lead_source_id' => 'nullable|exists:crm_lead_sources,id',
            'executive_id'   => 'nullable|exists:users,id',
            'observacoes'    => 'nullable|string',
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
            'proxima_acao'    => $v['proxima_acao'] ?? null,
            'proxima_acao_at' => $v['proxima_acao_at'] ?? null,
        ], fn ($x) => $x !== null);
        if (array_key_exists('proxima_acao_at', $v)) $profileData['proxima_acao_at'] = $v['proxima_acao_at']; // permite limpar

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
