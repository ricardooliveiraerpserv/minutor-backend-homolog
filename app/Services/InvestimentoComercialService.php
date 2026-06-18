<?php

namespace App\Services;

use App\Models\ContractType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ServiceType;
use Illuminate\Support\Facades\DB;

/**
 * Integração CRM ↔ Investimento Comercial.
 *
 * - Lead criado no CRM → cria o "lead-projeto" sob o Investimento Comercial da ERPSERV
 *   (onde caem os apontamentos de prospecção / custo de aquisição).
 * - Lead GANHO → garante os investimentos do novo cliente e MIGRA os apontamentos de
 *   prospecção para o Investimento Comercial DO CLIENTE (atribui o custo de aquisição).
 * - Lead perdido/cancelado → nada migra (custo fica na árvore da ERPSERV).
 *
 * Doc: docs/crm-lead-investimento-integracao.md
 */
class InvestimentoComercialService
{
    /** Cria o lead-projeto sob "Investimento Comercial" da ERPSERV. Roda com privilégio de sistema. */
    public function criarLeadProjeto(Customer $lead): ?Project
    {
        $erpserv = Customer::whereRaw('UPPER(name) = ?', ['ERPSERV'])->first();
        if (!$erpserv) {
            return null;
        }

        $serviceTypeId  = ServiceType::where('code', 'projeto')->value('id');
        $contractTypeId = ContractType::where('code', 'on_demand')->value('id');
        if (!$serviceTypeId || !$contractTypeId) {
            return null;
        }

        // Pai = nó "Investimento Comercial" da ERPSERV (mesmo destino do antigo botão "+ Lead").
        $parent = Project::where('customer_id', $erpserv->id)
            ->where('is_investimento_comercial', true)
            ->where('categoria_interna', 'Comercial')
            ->first();

        $codeKey = $erpserv->code_prefix ?: (string) $erpserv->id;
        $prefix  = "IC-{$codeKey}-";
        $maxSeq  = Project::withTrashed()
            ->where('code', 'like', $prefix . '%')
            ->get()
            ->map(fn ($p) => (int) preg_replace('/^.*-/', '', $p->code))
            ->max() ?? 0;
        $code = $prefix . ($maxSeq + 1);

        return Project::create([
            'name'                      => $lead->name,
            'code'                      => $code,
            'customer_id'               => $erpserv->id,
            'service_type_id'           => $serviceTypeId,
            'contract_type_id'          => $contractTypeId,
            'status'                    => 'started',
            'is_investimento_comercial' => true,
            'is_manual_code'            => true,
            'categoria_interna'         => 'Leads',
            'parent_project_id'         => $parent?->id,
        ]);
    }

    /**
     * Garante os 3 projetos de investimento do cliente (idempotente por código) e devolve o
     * nó "Investimento Comercial" (categoria Comercial), destino dos apontamentos de aquisição.
     */
    public function garantirInvestimentosDoCliente(Customer $customer): ?Project
    {
        $serviceTypeId  = ServiceType::where('code', 'projeto')->value('id');
        $contractTypeId = ContractType::where('code', 'on_demand')->value('id');

        if ($serviceTypeId && $contractTypeId) {
            $codeKey = $customer->code_prefix ?: (string) $customer->id;
            $defaults = [
                ['name' => 'Investimento Comercial', 'code' => "IC-{$codeKey}", 'categoria' => 'Comercial'],
                ['name' => 'Investimento Suporte',   'code' => "IS-{$codeKey}", 'categoria' => 'Suporte'],
                ['name' => 'Investimento Projetos',  'code' => "IP-{$codeKey}", 'categoria' => 'Projeto'],
            ];
            foreach ($defaults as $cfg) {
                if (Project::withTrashed()->where('code', $cfg['code'])->exists()) {
                    continue;
                }
                Project::create([
                    'name'                      => $cfg['name'],
                    'code'                      => $cfg['code'],
                    'customer_id'               => $customer->id,
                    'service_type_id'           => $serviceTypeId,
                    'contract_type_id'          => $contractTypeId,
                    'status'                    => 'started',
                    'is_investimento_comercial' => true,
                    'is_manual_code'            => true,
                    'categoria_interna'         => $cfg['categoria'],
                ]);
            }
        }

        return Project::where('customer_id', $customer->id)
            ->where('is_investimento_comercial', true)
            ->where('categoria_interna', 'Comercial')
            ->first();
    }

    /**
     * Migra TODOS os apontamentos (abertos e fechados) do lead-projeto para o Investimento
     * Comercial do cliente. Ajusta `project_id` E `customer_id` (o custo passa a ser do
     * cliente); preserva `real_project_id` (quem aprova). Não toca apontamentos soft-deletados.
     * Retorna a quantidade migrada.
     */
    public function migrarApontamentosDeAquisicao(Project $leadProjeto, Project $destino): int
    {
        if ($leadProjeto->id === $destino->id) {
            return 0;
        }

        return DB::table('timesheets')
            ->where('project_id', $leadProjeto->id)
            ->whereNull('deleted_at')
            ->update([
                'project_id'  => $destino->id,
                'customer_id' => $destino->customer_id,
                'updated_at'  => now(),
            ]);
    }

    /**
     * Conversão lead→cliente: garante investimentos do cliente e migra os apontamentos de
     * prospecção do lead-projeto vinculado. Mantém o lead-projeto (nó vazio = rastro).
     * Retorna ['destino' => Project|null, 'migrados' => int].
     */
    public function promoverLeadParaCliente(Customer $customer): array
    {
        $destino = $this->garantirInvestimentosDoCliente($customer);
        $migrados = 0;

        $leadProjetoId = optional($customer->crmProfile)->investimento_project_id;
        if ($destino && $leadProjetoId) {
            $leadProjeto = Project::find($leadProjetoId);
            if ($leadProjeto) {
                $migrados = $this->migrarApontamentosDeAquisicao($leadProjeto, $destino);
            }
        }

        return ['destino' => $destino, 'migrados' => $migrados];
    }
}
