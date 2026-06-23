<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractReleaseChecklistItem;
use Illuminate\Support\Carbon;

/**
 * Fase 4.6 — Indicadores e métricas do ciclo (comercial → jurídico → operacional).
 *
 * SOMENTE LEITURA: agrega os eventos já existentes (via CorporateTimelineService + tabelas de domínio)
 * por código comercial. NENHUMA tabela nova. Sem gate de projeto, sem automações.
 */
class ContractMetricsService
{
    public function __construct(private CorporateTimelineService $timeline) {}

    /** Contratos do funil (originados no CRM = têm código compartilhado proposta↔contrato). */
    private function funnel()
    {
        return Contract::whereNotNull('crm_proposal_id')
            ->get(['id', 'status', 'tipo_faturamento', 'customer_id', 'project_code_preview', 'crm_proposal_id', 'bloqueado_em', 'motivo_bloqueio', 'liberado_em']);
    }

    private function stats(array $hours): array
    {
        $v = array_values(array_filter($hours, fn ($h) => $h !== null));
        sort($v);
        return [
            'n'     => count($v),
            'media' => $v ? round(array_sum($v) / count($v), 1) : null,
            'min'   => $v ? $v[0] : null,
            'max'   => $v ? end($v) : null,
        ];
    }

    /** Durações entre marcos (horas) — médias/min/máx + recortes por cliente e por tipo. */
    public function durations(): array
    {
        $contracts = $this->funnel();
        $custName = \App\Models\Customer::whereIn('id', $contracts->pluck('customer_id')->filter()->unique())->pluck('name', 'id');

        $buckets = [
            'proposta_ate_aceite' => [], 'aceite_ate_contrato' => [], 'contrato_ate_assinatura' => [],
            'assinatura_ate_liberacao' => [], 'liberacao_ate_projeto' => [],
        ];
        $porCliente = []; // contrato→assinatura por cliente
        $porTipo = [];    // assinatura→liberação por tipo de contrato

        foreach ($contracts as $c) {
            if (!$c->project_code_preview) continue;
            $h = $this->timeline->indicadores($c->project_code_preview)['horas'];
            foreach ($buckets as $k => &$arr) {
                if (($h[$k] ?? null) !== null) $arr[] = $h[$k];
            }
            unset($arr);
            if (($h['contrato_ate_assinatura'] ?? null) !== null) {
                $nome = $custName[$c->customer_id] ?? '—';
                $porCliente[$nome][] = $h['contrato_ate_assinatura'];
            }
            if (($h['assinatura_ate_liberacao'] ?? null) !== null) {
                $porTipo[$c->tipo_faturamento ?: 'indefinido'][] = $h['assinatura_ate_liberacao'];
            }
        }

        return [
            'proposta_ate_aceite'      => $this->stats($buckets['proposta_ate_aceite']),
            'aceite_ate_contrato'      => $this->stats($buckets['aceite_ate_contrato']),
            'contrato_ate_assinatura'  => $this->stats($buckets['contrato_ate_assinatura']),
            'assinatura_ate_liberacao' => $this->stats($buckets['assinatura_ate_liberacao']),
            'liberacao_ate_projeto'    => $this->stats($buckets['liberacao_ate_projeto']), // preparado (gate ainda não existe)
            'contrato_ate_assinatura_por_cliente' => collect($porCliente)->map(fn ($a, $k) => ['cliente' => $k] + $this->stats($a))->values()->all(),
            'assinatura_ate_liberacao_por_tipo'   => collect($porTipo)->map(fn ($a, $k) => ['tipo' => $k] + $this->stats($a))->values()->all(),
        ];
    }

    /** Cards de contratos por estado do funil. */
    public function cards(): array
    {
        $f = $this->funnel();
        return [
            'aguardando_assinatura' => $f->where('status', Contract::STATUS_AGUARDANDO_ASSINATURA)->count(),
            'aguardando_liberacao'  => $f->where('status', Contract::STATUS_AGUARDANDO_LIBERACAO)->count(),
            'bloqueados'            => $f->whereNotNull('bloqueado_em')->count(),
            'liberados'             => $f->whereIn('status', [Contract::STATUS_LIBERADO_EXECUCAO, Contract::STATUS_PROJETO_GERADO, Contract::STATUS_ATIVO])->count(),
        ];
    }

    /** Indicadores do Checklist de Liberação. */
    public function checklist(): array
    {
        $ids = $this->funnel()->pluck('id');
        $items = ContractReleaseChecklistItem::whereIn('contract_id', $ids)->get();
        $porItem = $items->groupBy('item_key')->map(function ($grp, $key) {
            $checked = $grp->where('checked', true);
            $tempos = $checked->filter(fn ($i) => $i->checked_at && $i->created_at)
                ->map(fn ($i) => Carbon::parse($i->created_at)->diffInHours(Carbon::parse($i->checked_at)));
            return [
                'item_key'      => $key,
                'pendentes'     => $grp->where('checked', false)->where('obrigatorio', true)->count(),
                'concluidos'    => $checked->count(),
                'tempo_medio_h' => $tempos->count() ? round($tempos->avg(), 1) : null,
            ];
        })->values();
        return [
            'mais_pendentes' => $porItem->sortByDesc('pendentes')->values()->all(),
        ];
    }

    /** Indicadores de bloqueio. */
    public function bloqueios(): array
    {
        $f = $this->funnel();
        $atuais = $f->whereNotNull('bloqueado_em');
        // Durações: pares bloqueado→desbloqueado no histórico (ContractEvent).
        $eventos = ContractEvent::whereIn('contract_id', $f->pluck('id'))
            ->whereIn('event_type', ['bloqueado', 'desbloqueado'])->orderBy('created_at')->get(['contract_id', 'event_type', 'created_at']);
        $durs = [];
        $abertos = [];
        foreach ($eventos as $e) {
            if ($e->event_type === 'bloqueado') {
                $abertos[$e->contract_id] = $e->created_at;
            } elseif (isset($abertos[$e->contract_id])) {
                $durs[] = Carbon::parse($abertos[$e->contract_id])->diffInHours(Carbon::parse($e->created_at));
                unset($abertos[$e->contract_id]);
            }
        }
        // Bloqueios atuais ainda abertos: agora - bloqueado_em.
        foreach ($atuais as $c) {
            $durs[] = Carbon::parse($c->bloqueado_em)->diffInHours(now());
        }
        return [
            'quantidade'       => $atuais->count(),
            'motivos'          => $atuais->groupBy('motivo_bloqueio')->map(fn ($g, $m) => ['motivo' => $m ?: '—', 'qtd' => $g->count()])->sortByDesc('qtd')->values()->all(),
            'tempo_medio_h'    => $durs ? round(array_sum($durs) / count($durs), 1) : null,
        ];
    }

    /** Resumo executivo consolidado (cards + durações-chave + bloqueios). */
    public function executivo(): array
    {
        $d = $this->durations();
        return [
            'cards'     => $this->cards(),
            'duracoes'  => [
                'proposta_ate_aceite'      => $d['proposta_ate_aceite'],
                'contrato_ate_assinatura'  => $d['contrato_ate_assinatura'],
                'assinatura_ate_liberacao' => $d['assinatura_ate_liberacao'],
            ],
            'bloqueios' => $this->bloqueios(),
        ];
    }
}
