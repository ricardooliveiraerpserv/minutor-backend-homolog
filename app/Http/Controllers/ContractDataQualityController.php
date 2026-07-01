<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use Illuminate\Http\JsonResponse;

/**
 * Saneamento cadastral de contratos (Item 2). SOMENTE LEITURA — nenhuma alteração
 * de dados. Diagnóstico do indicador administrativo "Contratos sem data de vencimento".
 */
class ContractDataQualityController extends Controller
{
    public function vencimentos(): JsonResponse
    {
        $base = Contract::query(); // SoftDeletes já exclui deletados
        $total = (clone $base)->count();
        $com   = (clone $base)->whereNotNull('data_vencimento')->count();
        $sem   = $total - $com;

        $semVenc = Contract::whereNull('data_vencimento')
            ->with(['customer:id,name,crm_status', 'contractType:id,name', 'executivoConta:id,name', 'vendedor:id,name'])
            ->orderByDesc('created_at')->get();

        // Agrupamentos do conjunto "sem vencimento"
        $porTipo = $semVenc->groupBy(fn ($c) => $c->contractType?->name ?? ($c->categoria ?: '—'))
            ->map->count()->sortDesc();
        $porStatus = $semVenc->groupBy(fn ($c) => $c->status ?? '—')->map->count()->sortDesc();
        $semProjeto = $semVenc->whereNull('project_id')->count();

        $lista = $semVenc->map(fn ($c) => [
            'id'           => $c->id,
            'projeto'      => $c->project_name ?: ('Contrato #' . $c->id),
            'cliente'      => $c->customer?->name ?? '—',
            'crm_status'   => $c->customer?->crm_status,
            'tipo'         => $c->contractType?->name ?? ($c->categoria ?: '—'),
            'tipo_faturamento' => $c->tipo_faturamento,
            'responsavel'  => $c->executivoConta?->name ?? $c->vendedor?->name ?? '—',
            'status'       => $c->status,
            'tem_projeto'  => (bool) $c->project_id,
            'criado_em'    => optional($c->created_at)->toDateString(),
        ])->values();

        return response()->json(['data' => [
            'total_contratos'       => $total,
            'com_vencimento'        => $com,
            'sem_vencimento'        => $sem,
            'pct_sem_vencimento'    => $total > 0 ? round($sem / $total * 100, 1) : 0.0,
            'sem_vencimento_sem_projeto' => $semProjeto,
            'por_tipo'              => $porTipo->map(fn ($qtd, $tipo) => ['tipo' => $tipo, 'qtd' => $qtd])->values(),
            'por_status'            => $porStatus->map(fn ($qtd, $st) => ['status' => $st, 'qtd' => $qtd])->values(),
            'contratos'             => $lista,
        ]]);
    }
}
