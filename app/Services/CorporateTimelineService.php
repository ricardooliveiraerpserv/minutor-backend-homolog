<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\CrmOpportunityEvent;
use App\Models\CrmProposal;
use App\Models\DocumentEvent;
use Illuminate\Support\Collection;

/**
 * Fase 4.1 — Timeline Corporativa ÚNICA por consolidação em LEITURA (sem tabela física).
 *
 * Junta os eventos já existentes (DocumentEvent / CrmOpportunityEvent / ContractEvent) pelo MESMO
 * código comercial (ESP012-26) e taga a categoria COMERCIAL | JURÍDICO | OPERACIONAL.
 * Materialização física só no futuro, se houver necessidade comprovada (volume/BI).
 */
class CorporateTimelineService
{
    private const CAT_COMERCIAL  = 'COMERCIAL';
    private const CAT_JURIDICO   = 'JURIDICO';
    private const CAT_OPERACIONAL = 'OPERACIONAL';

    /** Eventos de Document que são jurídicos (assinatura/contrato); o resto é comercial (portal da proposta). */
    private const DOC_JURIDICO = [
        'criado', 'regenerado', 'assinado', 'contrato_gerado',
        'assinatura_solicitada', 'assinatura_iniciada', 'assinatura_parcial', 'assinatura_concluida',
        'assinatura_recusada', 'assinatura_cancelada', 'assinatura_expirada',
    ];

    private const LABELS = [
        'enviado' => 'Proposta enviada', 'visualizado' => 'Proposta visualizada', 'revisitado' => 'Proposta revisitada',
        'baixado' => 'PDF baixado', 'aceito' => 'Proposta aceita', 'recusado' => 'Proposta recusada', 'expirado' => 'Proposta expirada',
        'contrato_gerado' => 'Contrato gerado', 'criado' => 'Documento gerado', 'regenerado' => 'Documento regerado', 'assinado' => 'Documento assinado',
        'assinatura_solicitada' => 'Contrato enviado para assinatura', 'assinatura_iniciada' => 'Assinatura iniciada',
        'assinatura_parcial' => 'Parcialmente assinado', 'assinatura_concluida' => 'Contrato assinado',
        'assinatura_recusada' => 'Assinatura recusada', 'assinatura_cancelada' => 'Assinatura cancelada', 'assinatura_expirada' => 'Assinatura expirada',
    ];

    /** @return array<int, array{categoria:string,tipo:string,label:string,em:?string,source:string}> */
    public function forCodigo(string $codigo): array
    {
        $codigo = trim($codigo);
        if ($codigo === '') return [];
        $itens = collect();

        // 1) DocumentEvent — eventos vêm tagueados por `codigo` (CrmProposalService::logEvent) OU
        // só por `document_id` (Document::logEvent, ex.: portal/assinatura). Resolve as duas formas:
        // os Documents da proposta e do contrato compartilham o mesmo `codigo`.
        $docIds = \App\Models\Document::where('codigo', $codigo)->pluck('id');
        foreach (DocumentEvent::where('codigo', $codigo)->orWhereIn('document_id', $docIds)->get(['event_type', 'created_at']) as $e) {
            $jur = in_array($e->event_type, self::DOC_JURIDICO, true);
            $itens->push($this->item($jur ? self::CAT_JURIDICO : self::CAT_COMERCIAL, $e->event_type, $e->created_at, 'document_event'));
        }

        // 2) CrmOpportunityEvent (via proposta → oportunidade) = COMERCIAL.
        $oppId = CrmProposal::where('codigo', $codigo)->whereNotNull('opportunity_id')->value('opportunity_id');
        if ($oppId) {
            foreach (CrmOpportunityEvent::where('opportunity_id', $oppId)->get(['event_type', 'to_value', 'created_at']) as $e) {
                $itens->push($this->item(self::CAT_COMERCIAL, $e->event_type, $e->created_at, 'crm_event', $e->to_value));
            }
        }

        // 3) ContractEvent (via contrato pelo código) = OPERACIONAL.
        $contract = Contract::where('project_code_preview', $codigo)->first(['id', 'liberado_em', 'bloqueado_em']);
        if ($contract) {
            foreach (ContractEvent::where('contract_id', $contract->id)->get(['event_type', 'created_at']) as $e) {
                $itens->push($this->item(self::CAT_OPERACIONAL, $e->event_type, $e->created_at, 'contract_event'));
            }
            if ($contract->liberado_em)  $itens->push($this->item(self::CAT_OPERACIONAL, 'contrato_liberado', $contract->liberado_em, 'contract'));
            if ($contract->bloqueado_em) $itens->push($this->item(self::CAT_OPERACIONAL, 'contrato_bloqueado', $contract->bloqueado_em, 'contract'));
        }

        return $itens->filter(fn ($i) => $i['em'] !== null)
            ->sortBy('em')->values()->all();
    }

    /** Indicadores estratégicos: durações entre marcos (Proposta→Aceite→Contrato→Assinatura→Liberação→Projeto). */
    public function indicadores(string $codigo): array
    {
        $tl = collect($this->forCodigo($codigo));
        $first = fn (array $tipos) => $tl->first(fn ($i) => in_array($i['tipo'], $tipos, true))['em'] ?? null;
        $marcos = [
            'proposta_enviada' => $first(['enviado']),
            'proposta_aceita'  => $first(['aceito']),
            'contrato_gerado'  => $first(['contrato_gerado']),
            'assinado'         => $first(['assinatura_concluida', 'assinado']),
            'liberado'         => $first(['contrato_liberado']),
            'projeto_criado'   => $first(['projeto_gerado', 'projeto_criado']),
        ];
        $diff = function (?string $a, ?string $b) {
            if (!$a || !$b) return null;
            return \Carbon\Carbon::parse($a)->diffInHours(\Carbon\Carbon::parse($b));
        };
        return [
            'marcos' => $marcos,
            'horas'  => [
                'proposta_ate_aceite'    => $diff($marcos['proposta_enviada'], $marcos['proposta_aceita']),
                'aceite_ate_contrato'    => $diff($marcos['proposta_aceita'], $marcos['contrato_gerado']),
                'contrato_ate_assinatura' => $diff($marcos['contrato_gerado'], $marcos['assinado']),
                'assinatura_ate_liberacao' => $diff($marcos['assinado'], $marcos['liberado']),
                'liberacao_ate_projeto'  => $diff($marcos['liberado'], $marcos['projeto_criado']),
            ],
        ];
    }

    private function item(string $cat, string $tipo, $em, string $source, ?string $label = null): array
    {
        return [
            'categoria' => $cat,
            'tipo'      => $tipo,
            'label'     => $label ?: (self::LABELS[$tipo] ?? str_replace('_', ' ', $tipo)),
            'em'        => $em ? \Carbon\Carbon::parse($em)->toIso8601String() : null,
            'source'    => $source,
        ];
    }
}
