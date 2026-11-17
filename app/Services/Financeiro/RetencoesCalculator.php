<?php

namespace App\Services\Financeiro;

use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinContractBilling;

/**
 * Calcula retenções e líquido de uma parcela de serviço.
 * Alíquotas: as do contrato (fin_contract_billings) quando preenchidas; senão as da empresa.
 * Dispensa: IRRF abaixo de R$ 10 não é retido; PIS+COFINS+CSLL somados abaixo de R$ 10 não
 * são retidos (Lei 10.833/2003, art. 31 §3º; IN RFB 1.234/2012). Nota de débito não tem retenção.
 */
class RetencoesCalculator
{
    public const MINIMO_RETENCAO = 10.0;

    /** @return array{ret_irrf:float,ret_csll:float,ret_pis:float,ret_cofins:float,ret_iss:float,valor_iss:float,valor_liquido:float} */
    public function calcular(float $bruto, float $desconto, string $documento, ?FinContractBilling $cfg, ?FinCompanySetting $empresa): array
    {
        $base = round(max(0, $bruto - $desconto), 2);
        $zero = ['ret_irrf' => 0.0, 'ret_csll' => 0.0, 'ret_pis' => 0.0, 'ret_cofins' => 0.0, 'ret_iss' => 0.0, 'valor_iss' => 0.0];

        if ($documento !== 'nfse' || $base <= 0) {
            return $zero + ['valor_liquido' => $base];
        }

        $aliq = fn (string $k) => (float) ($cfg?->{"aliquota_{$k}"} ?? $empresa?->{"aliquota_{$k}"} ?? 0);
        $r = $zero;

        if ($cfg?->reter_federais ?? true) {
            $irrf = round($base * $aliq('irrf') / 100, 2);
            $r['ret_irrf'] = $irrf >= self::MINIMO_RETENCAO ? $irrf : 0.0;

            $csll = round($base * $aliq('csll') / 100, 2);
            $pis = round($base * $aliq('pis') / 100, 2);
            $cofins = round($base * $aliq('cofins') / 100, 2);
            if ($csll + $pis + $cofins >= self::MINIMO_RETENCAO) {
                $r['ret_csll'] = $csll;
                $r['ret_pis'] = $pis;
                $r['ret_cofins'] = $cofins;
            }
        }

        $r['valor_iss'] = round($base * $aliq('iss') / 100, 2);
        if ($cfg?->iss_retido) {
            $r['ret_iss'] = $r['valor_iss'];
        }

        $ret = $r['ret_irrf'] + $r['ret_csll'] + $r['ret_pis'] + $r['ret_cofins'] + $r['ret_iss'];
        $r['valor_liquido'] = round($base - $ret, 2);

        return $r;
    }
}
