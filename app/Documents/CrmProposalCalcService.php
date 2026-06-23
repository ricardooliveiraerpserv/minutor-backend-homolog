<?php

namespace App\Documents;

use App\Models\CrmProposalCalc;

/**
 * Motor da Memória de Cálculo (Fase 1) — replica as fórmulas do Excel "MARGEM".
 * Parâmetros configuráveis (default = planilha): coordenação 20%, imposto 10%, custo fixo 40%.
 */
class CrmProposalCalcService
{
    private const DEFAULTS = [
        'pct_coordenacao'      => 0.20, // coordenação = ROUNDUP(horas_consultoria * 20%)
        'pct_imposto'          => 0.10, // imposto = 10% do faturamento
        'pct_custo_fixo'       => 0.40, // custo fixo = 40% do (faturamento - imposto)
        'pct_margem'           => 0.00, // markup adicional sobre o faturamento por hora
        'premio_executivo_pct' => 0.00,
        'premio_arquiteto_pct' => 0.00,
        'sdr_fixo'             => 0.00,
        'desconto_pct'         => 0.00,
    ];

    /** Calcula os outputs a partir dos inputs (modo por_hora | valor_fixo). */
    public function compute(array $inputs, string $modo = 'por_hora'): array
    {
        $p = array_merge(self::DEFAULTS, $inputs['params'] ?? []);

        $horasC   = (float) ($inputs['horas_consultoria'] ?? 0);
        $custoHC  = (float) ($inputs['custo_h_consultoria'] ?? 0);
        $custoHCo = (float) ($inputs['custo_h_coordenacao'] ?? 0);
        $vendaH   = (float) ($inputs['venda_h'] ?? 0);
        $comHoras = (float) ($inputs['comercial_horas'] ?? 0);
        $comCusto = (float) ($inputs['custo_h_comercial'] ?? 0);

        // Coordenação = ROUNDUP(horas_consultoria * %), igual ao Excel.
        $coordHoras = (int) ceil($horasC * $p['pct_coordenacao']);

        // CUSTOS
        $custoConsultoria = $horasC * $custoHC;
        $custoCoordenacao = $coordHoras * $custoHCo;
        $custoComercial   = $comHoras * $comCusto;

        // FATURAMENTO
        if ($modo === 'valor_fixo') {
            $faturamento   = (float) ($inputs['faturamento_fixo'] ?? 0);
            $fatConsultoria = 0.0; $fatCoordenacao = 0.0; $margemMarkup = 0.0;
        } else {
            $fatConsultoria = $horasC * $vendaH;
            $fatCoordenacao = $coordHoras * $vendaH;
            $margemMarkup   = ($fatConsultoria + $fatCoordenacao) * $p['pct_margem'];
            $faturamento    = $fatConsultoria + $fatCoordenacao + $margemMarkup;
        }

        $imposto   = $faturamento * $p['pct_imposto'];
        $custoFixo = ($faturamento - $imposto) * $p['pct_custo_fixo'];
        $custoTotal = $custoConsultoria + $custoCoordenacao + $custoComercial + $imposto + $custoFixo;

        // PRÊMIOS (sobre faturamento - imposto) + SDR fixo
        $premioExec = ($faturamento - $imposto) * $p['premio_executivo_pct'];
        $premioArq  = ($faturamento - $imposto) * $p['premio_arquiteto_pct'];
        $premiosTotal = $premioExec + $premioArq + (float) $p['sdr_fixo'];

        // DESCONTO + líquidos
        $descontoValor      = $faturamento * $p['desconto_pct'];
        $faturamentoLiquido = $faturamento - $descontoValor;
        $custoMaisPremios   = $custoTotal + $premiosTotal;
        $margemPct    = $faturamentoLiquido > 0 ? ($faturamentoLiquido - $custoMaisPremios) / $faturamentoLiquido : 0.0;
        $lucroLiquido = $faturamentoLiquido - $custoMaisPremios;

        $r2 = fn ($v) => round((float) $v, 2);

        return [
            'modo_faturamento' => $modo,
            'coordenacao_horas' => $coordHoras,
            'custos' => [
                'consultoria' => $r2($custoConsultoria),
                'coordenacao' => $r2($custoCoordenacao),
                'comercial'   => $r2($custoComercial),
                'imposto'     => $r2($imposto),
                'custo_fixo'  => $r2($custoFixo),
            ],
            'faturamento_detalhe' => [
                'consultoria' => $r2($fatConsultoria),
                'coordenacao' => $r2($fatCoordenacao),
                'margem_markup' => $r2($margemMarkup),
            ],
            'premios' => ['executivo' => $r2($premioExec), 'arquiteto' => $r2($premioArq), 'sdr_fixo' => $r2($p['sdr_fixo'])],
            // Totalizadores (espelham as colunas)
            'custo_total'    => $r2($custoTotal),
            'faturamento'    => $r2($faturamento),
            'premios_total'  => $r2($premiosTotal),
            'desconto_valor' => $r2($descontoValor),
            'margem_pct'     => round($margemPct, 4),
            'lucro_liquido'  => $r2($lucroLiquido),
        ];
    }

    /** Persiste uma versão da memória (congelada). */
    public function persist(array $spec, array $inputs): CrmProposalCalc
    {
        $modo = $spec['modo_faturamento'] ?? 'por_hora';
        $out  = $this->compute($inputs, $modo);

        return CrmProposalCalc::create([
            'opportunity_id'   => $spec['opportunity_id'] ?? null,
            'proposal_id'      => $spec['proposal_id'] ?? null,
            'versao'           => $spec['versao'] ?? 1,
            'modo_faturamento' => $modo,
            'inputs'           => $inputs,
            'outputs'          => $out,
            'custo_total'      => $out['custo_total'],
            'faturamento'      => $out['faturamento'],
            'premios_total'    => $out['premios_total'],
            'desconto_valor'   => $out['desconto_valor'],
            'margem_pct'       => $out['margem_pct'],
            'lucro_liquido'    => $out['lucro_liquido'],
        ]);
    }
}
