<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CrmTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRM — perfil comercial da EMPRESA (mesma entidade customers): status do ciclo, perfil
 * firmográfico (1:1) e tags. Não duplica empresa; estende a existente.
 */
class CustomerCrmController extends Controller
{
    /** Dados CRM de uma empresa (status + perfil + tags). */
    public function show(Customer $customer): JsonResponse
    {
        $customer->load('crmProfile', 'tags', 'executive:id,name');
        return response()->json(['data' => [
            'customer_id' => $customer->id,
            'name'        => $customer->name,
            'cgc'         => $customer->cgc,
            'crm_status'  => $customer->crm_status,
            'executive'   => $customer->executive?->only(['id', 'name']),
            'profile'     => $customer->crmProfile,
            'tags'        => $customer->tags,
        ]]);
    }

    /** Upsert do status/perfil/tags da empresa. */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        // Normaliza o CGC (tira máscara) ANTES de validar — senão a checagem `unique`
        // compara a máscara do request contra os dígitos salvos e deixa passar duplicata
        // (o índice único do banco então derruba o save com erro 500 em vez de alerta claro).
        if ($request->has('cgc')) {
            $request->merge(['cgc' => filled($request->cgc) ? preg_replace('/[^0-9]/', '', $request->cgc) : null]);
        }

        $v = $request->validate([
            'crm_status'              => 'nullable|in:' . implode(',', Customer::CRM_STATUSES),
            'cgc'                     => ['nullable', 'string', 'unique:customers,cgc,' . $customer->id . ',id,deleted_at,NULL'],
            'executive_id'            => 'nullable|exists:users,id',
            'profile'                 => 'nullable|array',
            'profile.region'          => 'nullable|string|max:100',
            'profile.segment'         => 'nullable|string|max:100',
            'profile.porte'           => 'nullable|string|max:30',
            'profile.faturamento_estimado' => 'nullable|numeric|min:0',
            'profile.num_funcionarios'     => 'nullable|integer|min:0',
            'profile.erp_atual'       => 'nullable|string|max:120',
            'profile.indicacao'       => 'nullable|string|max:160',
            'tag_ids'                 => 'nullable|array',
            'tag_ids.*'               => 'integer|exists:crm_tags,id',
        ], [
            'cgc.unique' => 'Já existe uma empresa cadastrada com este CNPJ/CPF.',
        ]);

        // CNPJ/CPF (Item 1) — normaliza dígitos; pode ser limpado em lead/prospect.
        $cgcInformado = $customer->cgc;
        if (array_key_exists('cgc', $v)) {
            $cgcInformado = !empty($v['cgc']) ? preg_replace('/[^0-9]/', '', $v['cgc']) : null;
            if ($cgcInformado && !in_array(strlen($cgcInformado), [11, 14])) {
                return response()->json(['message' => 'CNPJ/CPF deve ter 11 (CPF) ou 14 (CNPJ) dígitos.', 'code' => 'INVALID_CGC_LENGTH'], 422);
            }
            $customer->cgc = $cgcInformado;
        }

        // Item 1 (Opção A): promover a cliente/contrato exige CNPJ preenchido.
        if (array_key_exists('crm_status', $v) && $v['crm_status']) {
            if (Customer::statusRequiresCgc($v['crm_status']) && empty($cgcInformado)) {
                return response()->json([
                    'message' => 'Informe o CNPJ/CPF da empresa antes de promovê-la a ' . $v['crm_status'] . '.',
                    'code' => 'CGC_REQUIRED',
                ], 422);
            }
            $customer->crm_status = $v['crm_status'];
        }
        if (array_key_exists('executive_id', $v)) $customer->executive_id = $v['executive_id'];
        $customer->save();

        if (array_key_exists('profile', $v) && is_array($v['profile'])) {
            $customer->crmProfile()->updateOrCreate(['customer_id' => $customer->id], $v['profile']);
        }
        if (array_key_exists('tag_ids', $v)) {
            $customer->tags()->sync($v['tag_ids'] ?? []);
        }

        return $this->show($customer->fresh());
    }

    /** Lista de tags (e cria sob demanda). */
    public function tagsIndex(Request $request): JsonResponse
    {
        $q = CrmTag::orderBy('name');
        if ($request->boolean('only_active')) $q->where('active', true);
        return response()->json(['data' => $q->get()]);
    }

    public function tagsStore(Request $request): JsonResponse
    {
        $v = $request->validate(['name' => 'required|string|max:60|unique:crm_tags,name', 'color' => 'nullable|string|max:16']);
        return response()->json(['data' => CrmTag::create($v + ['active' => true])], 201);
    }

    public function tagsUpdate(Request $request, CrmTag $tag): JsonResponse
    {
        $v = $request->validate([
            'name'   => 'sometimes|string|max:60|unique:crm_tags,name,' . $tag->id,
            'color'  => 'nullable|string|max:16',
            'active' => 'boolean',
        ]);
        $tag->update($v);
        return response()->json(['data' => $tag]);
    }

    public function tagsDestroy(CrmTag $tag): JsonResponse
    {
        $tag->customers()->detach(); // remove os vínculos com empresas
        $tag->delete();
        return response()->json(['data' => true]);
    }

    /**
     * Timeline única da empresa + vínculos (oportunidades/propostas/contratos/projetos).
     * Funde eventos do CRM (oportunidades) com eventos de contrato — a ponte comercial↔operação.
     */
    public function timeline(Customer $customer): JsonResponse
    {
        $oppIds = \App\Models\CrmOpportunity::where('customer_id', $customer->id)->pluck('id');
        $contractIds = \App\Models\Contract::where('customer_id', $customer->id)->pluck('id');

        $vinculos = [
            'oportunidades' => $oppIds->count(),
            'propostas'     => \App\Models\CrmProposal::whereIn('opportunity_id', $oppIds)->count(),
            'contratos'     => $contractIds->count(),
            'projetos'      => \App\Models\Project::where('customer_id', $customer->id)->count(),
        ];

        // Eventos no nível da empresa — fazem a timeline COMEÇAR na fase Lead.
        $leadEvents = \App\Models\CrmCustomerEvent::where('customer_id', $customer->id)
            ->with('triggeredBy:id,name')->orderByDesc('created_at')->limit(100)->get()
            ->map(fn ($e) => ['when' => $e->created_at, 'source' => 'lead', 'type' => $e->event_type, 'label' => $e->label, 'user' => $e->triggeredBy?->name]);

        $crmEvents = \App\Models\CrmOpportunityEvent::whereIn('opportunity_id', $oppIds)
            ->with('triggeredBy:id,name')->orderByDesc('created_at')->limit(100)->get()
            ->map(fn ($e) => ['when' => $e->created_at, 'source' => 'crm', 'type' => $e->event_type, 'label' => $e->to_value, 'user' => $e->triggeredBy?->name]);

        $contractEvents = \App\Models\ContractEvent::whereIn('contract_id', $contractIds)
            ->with('triggeredBy:id,name')->orderByDesc('created_at')->limit(100)->get()
            ->map(fn ($e) => ['when' => $e->created_at, 'source' => 'contrato', 'type' => $e->event_type, 'label' => $e->to_value ?? $e->field, 'user' => $e->triggeredBy?->name]);

        $timeline = $leadEvents->concat($crmEvents)->concat($contractEvents)
            ->sortByDesc('when')->values()->take(150);

        return response()->json(['data' => ['vinculos' => $vinculos, 'timeline' => $timeline]]);
    }
}
