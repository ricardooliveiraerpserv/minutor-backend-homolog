<?php

namespace App\Services\Financeiro\Nfse;

use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinInstallment;
use App\Models\Financeiro\FinInvoice;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;

/**
 * Monta a DPS (Declaração de Prestação de Serviço) no leiaute nacional v1.01
 * (esquemas_prod_restrita_20261020 / Anexo I SEFIN-ADN DPS v1.01 de 20/10/2026).
 * Ordem dos elementos = sequência do XSD (TCInfDPS, TCInfoPrestador, TCServ, TCInfoValores…).
 * Prestador é o emitente (tpEmit=1) → NÃO informa endereço do prestador (só obrigatório p/ tpEmit 2/3).
 * Grupo IBSCBS omitido (opcional; só com indicação da calculadora IBS/CBS).
 */
class DpsXmlBuilder
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';
    public const VERSAO = '1.01';

    public function construir(FinInvoice $nota, FinCompanySetting $empresa, FinInstallment $parcela, ?\DateTimeInterface $agora = null): DOMDocument
    {
        $cnpjPrest = $this->digitos($nota->company?->cnpj ?? $empresa->certificado_cnpj ?? '');
        if (strlen($cnpjPrest) !== 14) {
            throw new \InvalidArgumentException('CNPJ do prestador ausente (cadastro da empresa / certificado).');
        }
        $ibgeEmi = $this->digitos($empresa->codigo_ibge);
        $serie = (string) (int) ($nota->serie ?: 1);
        $nDps = (string) (int) $nota->numero_dps;
        $id = 'DPS' . $ibgeEmi . '2' . $cnpjPrest . str_pad($serie, 5, '0', STR_PAD_LEFT) . str_pad($nDps, 15, '0', STR_PAD_LEFT);

        $t = $nota->tomador ?? [];
        $docToma = $this->digitos($t['cgc'] ?? '');
        $agora = Carbon::instance($agora ?? now())->setTimezone('America/Sao_Paulo');

        $x = new DOMDocument('1.0', 'UTF-8');
        $x->preserveWhiteSpace = false;
        $x->formatOutput = false;

        $dps = $x->createElementNS(self::NS, 'DPS');
        $dps->setAttribute('versao', self::VERSAO);
        $x->appendChild($dps);

        $inf = $this->el($x, $dps, 'infDPS');
        $inf->setAttribute('Id', $id);
        $this->el($x, $inf, 'tpAmb', $empresa->nfse_ambiente === 'producao' ? '1' : '2');
        $this->el($x, $inf, 'dhEmi', $agora->format('Y-m-d\TH:i:sP'));
        $this->el($x, $inf, 'verAplic', 'Minutor-1.0');
        $this->el($x, $inf, 'serie', $serie);
        $this->el($x, $inf, 'nDPS', $nDps);
        $this->el($x, $inf, 'dCompet', $this->dataCompetencia($nota->competencia, $agora));
        $this->el($x, $inf, 'tpEmit', '1');
        $this->el($x, $inf, 'finNFSe', '0');
        $this->el($x, $inf, 'cLocEmi', $ibgeEmi);

        // Prestador (emitente)
        $prest = $this->el($x, $inf, 'prest');
        $this->el($x, $prest, 'CNPJ', $cnpjPrest);
        if (config('financeiro.nfse_informar_im') && $empresa->inscricao_municipal) {
            $this->el($x, $prest, 'IM', $this->limpo($empresa->inscricao_municipal, 15));
        }
        $reg = $this->el($x, $prest, 'regTrib');
        $this->el($x, $reg, 'opSimpNac', $empresa->regime_tributario === 'simples' ? '3' : '1');
        $this->el($x, $reg, 'regEspTrib', '0');

        // Tomador
        $toma = $this->el($x, $inf, 'toma');
        $this->el($x, $toma, strlen($docToma) === 11 ? 'CPF' : 'CNPJ', $docToma);
        if (! empty($t['inscricao_municipal']) && config('financeiro.nfse_informar_im')) {
            $this->el($x, $toma, 'IM', $this->limpo($t['inscricao_municipal'], 15));
        }
        $this->el($x, $toma, 'xNome', $this->limpo($t['company_name'] ?? $t['name'] ?? '', 300));
        $end = $this->el($x, $toma, 'end');
        $nac = $this->el($x, $end, 'endNac');
        $this->el($x, $nac, 'cMun', $this->digitos($t['codigo_ibge'] ?? ''));
        $this->el($x, $nac, 'CEP', $this->digitos($t['cep'] ?? ''));
        $this->el($x, $end, 'xLgr', $this->limpo($t['logradouro'] ?? '', 255));
        $this->el($x, $end, 'nro', $this->limpo($t['numero'] ?? 'S/N', 60));
        if (! empty($t['complemento'])) $this->el($x, $end, 'xCpl', $this->limpo($t['complemento'], 156));
        $this->el($x, $end, 'xBairro', $this->limpo(($t['bairro'] ?? '') ?: 'Centro', 60));
        $email = $this->primeiroEmail($t['email_cobranca'] ?? null);
        if ($email) $this->el($x, $toma, 'email', $email);

        // Serviço
        $serv = $this->el($x, $inf, 'serv');
        $loc = $this->el($x, $serv, 'locPrest');
        $this->el($x, $loc, 'cLocPrestacao', $ibgeEmi);
        $cs = $this->el($x, $serv, 'cServ');
        $this->el($x, $cs, 'cTribNac', $this->codigoTribNac($parcela, $empresa));
        $this->el($x, $cs, 'xDescServ', $this->limpo($nota->discriminacao ?: (string) $parcela->descricao, 2000, true));

        // Valores
        $val = $this->el($x, $inf, 'valores');
        $vsp = $this->el($x, $val, 'vServPrest');
        $this->el($x, $vsp, 'vServ', $this->dec($nota->valor_servico));
        if ((float) $nota->desconto > 0) {
            $d = $this->el($x, $val, 'vDescCondIncond');
            $this->el($x, $d, 'vDescIncond', $this->dec($nota->desconto));
        }
        $trib = $this->el($x, $val, 'trib');
        $tm = $this->el($x, $trib, 'tribMun');
        $this->el($x, $tm, 'tribISSQN', '1');
        $this->el($x, $tm, 'tpRetISSQN', (float) $parcela->ret_iss > 0 ? '2' : '1');

        $pcc = round((float) $parcela->ret_pis + (float) $parcela->ret_cofins + (float) $parcela->ret_csll, 2);
        if ((float) $parcela->ret_irrf > 0 || $pcc > 0) {
            $tf = $this->el($x, $trib, 'tribFed');
            if ($pcc > 0) {
                $pc = $this->el($x, $tf, 'piscofins');
                $this->el($x, $pc, 'CST', '01');
                // 3 = PIS/COFINS/CSLL retidos (Anexo I, tpRetPisCofins)
                $this->el($x, $pc, 'tpRetPisCofins', (float) $parcela->ret_csll > 0 ? '3' : '1');
            }
            if ((float) $parcela->ret_irrf > 0) $this->el($x, $tf, 'vRetIRRF', $this->dec($parcela->ret_irrf));
            // vRetCSLL = soma das contribuições retidas (PIS+COFINS+CSLL) — nota do Anexo I
            if ($pcc > 0) $this->el($x, $tf, 'vRetCSLL', $this->dec($pcc));
        }
        $tot = $this->el($x, $trib, 'totTrib');
        $this->el($x, $tot, 'indTotTrib', '0');

        return $x;
    }

    /** dCompet: 1º dia da competência (AAAA-MM-01), nunca no futuro. */
    private function dataCompetencia(string $ym, Carbon $agora): string
    {
        $d = Carbon::createFromFormat('Y-m-d', $ym . '-01', 'America/Sao_Paulo');
        return ($d->greaterThan($agora) ? $agora : $d)->format('Y-m-d');
    }

    /** 01.07.01 / 010701 / 01.07 → 6 dígitos (subitem com desdobro "01" quando vier só item.subitem). */
    private function codigoTribNac(FinInstallment $p, FinCompanySetting $empresa): string
    {
        $cfg = $p->contract_id ? \App\Models\Financeiro\FinContractBilling::firstWhere('contract_id', $p->contract_id) : null;
        $raw = $this->digitos($cfg?->codigo_tributacao_nacional ?: $empresa->codigo_tributacao_nacional ?: '');
        if (strlen($raw) === 4) $raw .= '01';
        if (strlen($raw) !== 6) {
            throw new \InvalidArgumentException('Código de tributação nacional inválido (6 dígitos, ex.: 01.07.01) na empresa/contrato.');
        }
        return $raw;
    }

    private function el(DOMDocument $x, DOMElement $pai, string $nome, ?string $valor = null): DOMElement
    {
        $e = $x->createElementNS(self::NS, $nome);
        if ($valor !== null) $e->appendChild($x->createTextNode($valor));
        $pai->appendChild($e);
        return $e;
    }

    private function dec($v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    private function digitos(?string $s): string
    {
        return preg_replace('/\D/', '', (string) $s);
    }

    private function limpo(?string $s, int $max, bool $quebras = false): string
    {
        $s = (string) $s;
        $s = $quebras ? preg_replace("/[ \t]+/", ' ', $s) : preg_replace('/\s+/', ' ', $s);
        $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s));
        return mb_substr($s, 0, $max);
    }

    private function primeiroEmail(?string $s): ?string
    {
        foreach (preg_split('/[;,\s]+/', (string) $s, -1, PREG_SPLIT_NO_EMPTY) as $e) {
            if (filter_var($e, FILTER_VALIDATE_EMAIL) && mb_strlen($e) <= 80) return $e;
        }
        return null;
    }
}
