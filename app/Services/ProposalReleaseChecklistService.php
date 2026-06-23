<?php

namespace App\Services;

use App\Models\ContractReleaseChecklistItem as Item;
use App\Models\ContractReleaseChecklistTemplate as Template;
use App\Models\CrmProposal;
use Illuminate\Support\Collection;

/**
 * Liberação operacional PROPOSAL-CENTRIC. Reaproveita os templates configuráveis (seed da 4.1),
 * mas instancia o checklist na PROPOSTA (crm_proposal_id) — sem depender de Contract.
 *
 * O item-âncora "contrato_assinado" é AUTO-marcado quando o cliente possui cobertura jurídica
 * (Contrato Guarda-Chuva = metadado). Sem guarda-chuva, fica manual (formalização à parte).
 */
class ProposalReleaseChecklistService
{
    private const TIPO_FAT_MAP = [
        'bh_fixo' => 'banco_horas_fixo', 'bh_mensal' => 'banco_horas_mensal',
        'on_demand' => 'on_demand', 'projeto_fechado' => 'por_servico', 'cloud' => 'saas',
    ];

    /** Templates aplicáveis (precedência: tipo da proposta → default). */
    public function templatesFor(CrmProposal $p): Collection
    {
        $tipoFat = self::TIPO_FAT_MAP[$p->tipo ?? ''] ?? null;
        if ($tipoFat) {
            $rows = Template::where('ativo', true)->where('scope_type', 'tipo_faturamento')->where('scope_value', $tipoFat)->orderBy('ordem')->get();
            if ($rows->isNotEmpty()) return $rows;
        }
        return Template::where('ativo', true)->where('scope_type', 'default')->whereNull('scope_value')->orderBy('ordem')->get();
    }

    /** Item-âncora da cobertura jurídica (auto quando há Contrato Guarda-Chuva). */
    public const ITEM_JURIDICO = 'cobertura_juridica_confirmada';

    /** Instancia (idempotente) o checklist da proposta + auto-check de cobertura jurídica. */
    public function instanciar(CrmProposal $p): Collection
    {
        foreach ($this->templatesFor($p) as $t) {
            Item::firstOrCreate(
                ['crm_proposal_id' => $p->id, 'item_key' => $t->item_key],
                ['contract_id' => null, 'label' => $t->label, 'obrigatorio' => $t->obrigatorio, 'aplicavel' => true,
                 'checked' => false, 'ordem' => $t->ordem, 'owner_role' => $t->owner_role, 'sla_days' => $t->sla_days],
            );
        }
        $this->sincronizarJuridico($p);
        return $p->releaseChecklist()->get();
    }

    /** Cobertura jurídica via Contrato Guarda-Chuva (metadado) → auto-check (sem gestão contratual). */
    public function sincronizarJuridico(CrmProposal $p): void
    {
        $coberto = filled($p->umbrella_ref) || filled(optional($p->customer)->umbrella_contract_numero);
        if ($coberto) {
            $this->marcar($p, self::ITEM_JURIDICO, true, null);
        }
    }

    /** Indica se o cliente/proposta tem cobertura jurídica (guarda-chuva) — controla o auto-check. */
    public function temCobertura(CrmProposal $p): bool
    {
        return filled($p->umbrella_ref) || filled(optional($p->customer)->umbrella_contract_numero);
    }

    /**
     * Pendências PARALELAS (itens obrigatórios não concluídos) com área/dias/SLA.
     * @return array<int,array{item_key:string,label:string,owner_role:?string,owner_label:string,dias:int,sla_days:?int,atrasado:bool}>
     */
    public function proximasAcoes(CrmProposal $p): array
    {
        $now = now();
        return $p->releaseChecklist()->where('obrigatorio', true)->where('aplicavel', true)->where('checked', false)
            ->orderBy('ordem')->get()->map(function ($it) use ($now) {
                $dias = $it->created_at ? (int) $it->created_at->diffInDays($now) : 0;
                return [
                    'item_key' => $it->item_key, 'label' => $it->label, 'owner_role' => $it->owner_role,
                    'owner_label' => Item::OWNER_LABELS[$it->owner_role] ?? ($it->owner_role ?: '—'),
                    'dias' => $dias, 'sla_days' => $it->sla_days,
                    'atrasado' => $it->sla_days !== null && $dias > $it->sla_days,
                ];
            })->values()->all();
    }

    public function marcar(CrmProposal $p, string $itemKey, bool $checked, ?int $userId): ?Item
    {
        $item = $p->releaseChecklist()->where('item_key', $itemKey)->first();
        if (!$item) return null;
        $item->update([
            'checked'    => $checked,
            'checked_by' => $checked ? $userId : null,
            'checked_at' => $checked ? now() : null,
        ]);
        return $item;
    }

    public function podeLiberar(CrmProposal $p): bool
    {
        return $p->releaseChecklist()->where('obrigatorio', true)->where('aplicavel', true)->where('checked', false)->count() === 0;
    }
}
