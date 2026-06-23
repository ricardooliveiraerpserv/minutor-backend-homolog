<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractReleaseChecklistItem as Item;
use App\Models\ContractReleaseChecklistTemplate as Template;
use Illuminate\Support\Collection;

/**
 * Fase 4.1 — Checklist de Liberação configurável.
 *
 * Resolve os itens exigidos por ESCOPO (precedência: tipo_faturamento → categoria → contract_type → default)
 * e instancia um snapshot por contrato. A liberação operacional só é permitida com todos os itens
 * OBRIGATÓRIOS + APLICÁVEIS marcados.
 */
class ContractReleaseChecklistService
{
    /** Templates aplicáveis ao contrato, pela precedência de escopo (mais específico vence). */
    public function templatesFor(Contract $contract): Collection
    {
        $tentativas = [
            ['tipo_faturamento', $contract->tipo_faturamento],
            ['categoria', $contract->categoria],
            ['contract_type', $contract->contract_type_id ? (string) $contract->contract_type_id : null],
            ['default', null],
        ];
        foreach ($tentativas as [$type, $value]) {
            $q = Template::where('ativo', true)->where('scope_type', $type);
            $value === null ? $q->whereNull('scope_value') : $q->where('scope_value', $value);
            $rows = $q->orderBy('ordem')->get();
            if ($rows->isNotEmpty()) return $rows;
        }
        return collect();
    }

    /** Cria/atualiza os itens do checklist do contrato a partir dos templates. Idempotente. */
    public function instanciar(Contract $contract): Collection
    {
        $templates = $this->templatesFor($contract);
        foreach ($templates as $t) {
            Item::firstOrCreate(
                ['contract_id' => $contract->id, 'item_key' => $t->item_key],
                ['label' => $t->label, 'obrigatorio' => $t->obrigatorio, 'aplicavel' => true, 'checked' => false, 'ordem' => $t->ordem],
            );
        }
        return $contract->releaseChecklist()->get();
    }

    /** Marca/desmarca um item (auditando quem marcou). */
    public function marcar(Contract $contract, string $itemKey, bool $checked, ?int $userId): ?Item
    {
        $item = $contract->releaseChecklist()->where('item_key', $itemKey)->first();
        if (!$item) return null;
        $item->update([
            'checked'    => $checked,
            'checked_by' => $checked ? $userId : null,
            'checked_at' => $checked ? now() : null,
        ]);
        return $item;
    }

    /** Auto-marca 'contrato_assinado' quando o Document do contrato está assinado. */
    public function sincronizarAssinatura(Contract $contract, bool $assinado): void
    {
        if (!$assinado) return;
        $this->marcar($contract, 'contrato_assinado', true, null);
    }

    /** Liberação permitida? Todos os itens obrigatórios+aplicáveis marcados. */
    public function podeLiberar(Contract $contract): bool
    {
        $pendentes = $contract->releaseChecklist()
            ->where('obrigatorio', true)->where('aplicavel', true)->where('checked', false)->count();
        return $pendentes === 0;
    }
}
