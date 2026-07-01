<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityEvent;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRM — Conversão comercial: oportunidade GANHA → Contrato (reusa a entidade/fluxo de contrato).
 * O contrato nasce em rascunho/backlog e segue o fluxo normal do Kanban de Contratos (onde o
 * projeto é gerado). Idempotente: 1 oportunidade → 1 contrato.
 */
class CrmConversionController extends Controller
{
    public function convert(Request $request, CrmOpportunity $opportunity): JsonResponse
    {
        if ($opportunity->status !== 'ganho') {
            return response()->json(['message' => 'Só oportunidades GANHAS podem ser convertidas.'], 422);
        }
        if ($opportunity->contract_id) {
            return response()->json(['message' => 'Esta oportunidade já foi convertida em contrato.', 'contract_id' => $opportunity->contract_id], 422);
        }

        $v = $request->validate([
            'categoria'        => 'required|in:projeto,sustentacao',
            'contract_type_id' => 'nullable|exists:contract_types,id',
            'service_type_id'  => 'nullable|exists:service_types,id',
            'tipo_faturamento' => 'nullable|in:on_demand,banco_horas_mensal,banco_horas_fixo,por_servico,saas',
            'horas_contratadas' => 'nullable|integer|min:0',
            'valor_projeto'    => 'nullable|numeric|min:0',
            'valor_hora'       => 'nullable|numeric|min:0',
            'observacoes'      => 'nullable|string',
        ]);

        $customer = Customer::find($opportunity->customer_id);

        // Item 1 (Opção A): contrato exige empresa com CNPJ/CPF (vira cliente).
        if ($customer && empty($customer->cgc)) {
            return response()->json([
                'message' => 'Informe o CNPJ/CPF da empresa antes de gerar o contrato.',
                'code' => 'CGC_REQUIRED',
            ], 422);
        }

        $migracao = ['destino' => null, 'migrados' => 0];

        $contract = DB::transaction(function () use ($opportunity, $customer, $v, &$migracao) {
            $contract = Contract::create([
                'customer_id'        => $opportunity->customer_id,
                'project_name'       => $opportunity->title,
                'categoria'          => $v['categoria'],
                'contract_type_id'   => $v['contract_type_id'] ?? null,
                'service_type_id'    => $v['service_type_id'] ?? null,
                'tipo_faturamento'   => $v['tipo_faturamento'] ?? null,
                'horas_contratadas'  => $v['horas_contratadas'] ?? 0,
                'valor_projeto'      => $v['valor_projeto'] ?? ($opportunity->valor ?: null),
                'valor_hora'         => $v['valor_hora'] ?? null,
                // Opção B (papéis distintos): vendedor = responsável comercial da oportunidade;
                // executivo da conta = relacionamento/pós-venda (customers.executive_id).
                'executivo_conta_id' => $customer?->executive_id,
                'vendedor_id'        => $opportunity->responsavel_id,
                'observacoes'        => $v['observacoes'] ?? $opportunity->notas,
                'status'             => Contract::STATUS_RASCUNHO,
                'kanban_status'      => Contract::KANBAN_BACKLOG,
                'created_by_id'      => auth()->id(),
            ]);

            $opportunity->update(['contract_id' => $contract->id]);
            CrmOpportunityEvent::log($opportunity->id, 'converted', [
                'to_value' => "Contrato #{$contract->id}",
                'meta'     => ['contract_id' => $contract->id],
            ]);

            // Empresa única: ao converter, avança o status comercial.
            if ($customer && in_array($customer->crm_status, ['lead', 'prospect', 'cliente'], true)) {
                $customer->update(['crm_status' => 'cliente']);
            }

            // Integração CRM ↔ Investimento Comercial: garante os investimentos do novo
            // cliente e migra os apontamentos de prospecção (custo de aquisição) do
            // lead-projeto para o Investimento Comercial DO CLIENTE. Mantém o nó da ERPSERV.
            if ($customer) {
                $migracao = app(\App\Services\InvestimentoComercialService::class)
                    ->promoverLeadParaCliente($customer->fresh('crmProfile'));
            }

            return $contract;
        });

        return response()->json([
            'ok' => true,
            'contract' => ['id' => $contract->id, 'status' => $contract->status, 'kanban_status' => $contract->kanban_status],
            'apontamentos_migrados' => $migracao['migrados'],
            'investimento_comercial_id' => optional($migracao['destino'])->id,
            'message' => 'Contrato criado em "Novo Contrato". Gere o projeto pelo Kanban de Contratos.',
        ], 201);
    }
}
