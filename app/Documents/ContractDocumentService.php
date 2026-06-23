<?php

namespace App\Documents;

use App\Models\Contract;
use App\Models\Document;
use App\Models\User;

/**
 * Item 1 — Document OFICIAL do contrato (document_type=contrato).
 *
 * Gera o PDF do contrato pela MESMA Plataforma de Documentos da proposta (versionamento,
 * histórico, Attachment e auditoria próprios). Este é o artefato que vai à assinatura (Clicksign).
 * O documento nasce com status_assinatura = nao_enviado.
 */
class ContractDocumentService
{
    public function __construct(
        private DocumentService $documents,
        private CrmProposalService $proposals, // reuso de contratadaConfig()
    ) {}

    private const TIPO_FAT_LABELS = [
        'banco_horas_fixo' => 'Banco de Horas Fixo', 'banco_horas_mensal' => 'Banco de Horas Mensal',
        'on_demand' => 'Consultoria Sob Demanda', 'por_servico' => 'Projeto Fechado', 'saas' => 'Cloud / SaaS',
    ];

    public function gerar(Contract $contract, User $actor, bool $sync = true): Document
    {
        $contract->loadMissing(['customer', 'crmProposal', 'contractType']);
        $codigo = $contract->project_code_preview ?: ('CONTRATO-' . $contract->id);

        // Versão: regenera in-place enquanto NÃO foi enviado p/ assinatura; depois disso, nova versão.
        $existing = Document::where('document_type', 'contrato')
            ->where('entity_type', 'CONTRACT')->where('entity_id', $contract->id)
            ->orderByDesc('versao')->first();
        $jaEnviado = $existing && !in_array($existing->status_assinatura, [null, Document::SIG_NAO_ENVIADO], true);

        $spec = [
            'document_type' => 'contrato',
            'template'      => 'pdf.documents.contrato.render',
            'renderer'      => 'chromium',
            'entity_type'   => 'CONTRACT',
            'entity_id'     => $contract->id,
            'codigo'        => $codigo,
            'status'        => 'gerado',
            'opts'          => ['paperWidth' => 8.27, 'paperHeight' => 11.69, 'marginTop' => 0.6, 'marginBottom' => 0.6, 'marginLeft' => 0.7, 'marginRight' => 0.7, 'preferCssPageSize' => true],
        ];
        $data = $this->renderData($contract, $codigo, $jaEnviado ? (($existing->versao ?? 1) + 1) : ($existing->versao ?? 1));

        if ($jaEnviado) {
            $doc = $sync ? $this->documents->newVersion($spec, $data, $actor, false) : $this->documents->newVersion($spec, $data, $actor, true);
        } else {
            $spec['versao'] = $existing?->versao ?? 1;
            $doc = $sync ? $this->documents->generate($spec, $data, $actor) : $this->documents->generateAsync($spec, $data, $actor);
        }

        if ($doc->status_assinatura === null) {
            $doc->update(['status_assinatura' => Document::SIG_NAO_ENVIADO]);
        }
        $patch = ['contract_document_id' => $doc->id];
        // Status operacional: rascunho → emitido (documento oficial gerado).
        if (in_array($contract->status, [Contract::STATUS_RASCUNHO], true)) {
            $patch['status'] = Contract::STATUS_EMITIDO;
        }
        $contract->update($patch);
        // Instancia o Checklist de Liberação configurável (idempotente).
        app(\App\Services\ContractReleaseChecklistService::class)->instanciar($contract);
        return $doc->fresh();
    }

    private function renderData(Contract $contract, string $codigo, int $versao): array
    {
        $brl = fn ($v) => $v ? 'R$ ' . number_format((float) $v, 2, ',', '.') : null;
        $snap = (array) ($contract->proposal_calc_snapshot ?? []);
        $dur  = (int) (($snap['inputs']['duracao_meses'] ?? 0));
        $cust = $contract->customer;
        $prop = $contract->crmProposal;

        return [
            'codigo'      => $codigo,
            'versao'      => $versao,
            'data'        => now()->format('d/m/Y'),
            'contratada'  => $this->proposals->contratadaConfig(),
            'contratante' => [
                'nome' => $cust?->company_name ?: ($cust?->name ?? '—'),
                'cnpj' => $cust?->cgc ?? '—',
            ],
            'objeto'                 => $contract->project_name,
            'tipo_faturamento_label' => self::TIPO_FAT_LABELS[$contract->tipo_faturamento] ?? ($contract->contractType?->name ?? '—'),
            'horas'                  => (int) $contract->horas_contratadas,
            'horas_coordenacao'      => (int) $contract->horas_coordenacao,
            'valor_hora'             => $brl($contract->valor_hora),
            'valor_projeto'          => $brl($contract->valor_projeto) ?? '—',
            'escopo'                 => $contract->observacoes,
            'condicao_pagamento'     => $contract->condicao_pagamento,
            'vigencia_meses'         => $dur ?: null,
            'proposta_ref'           => $prop ? ($prop->codigo . ' V' . $prop->versao) : null,
        ];
    }
}
