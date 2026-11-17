<?php

namespace App\Services\Financeiro\Nfse;

use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinInvoice;

/**
 * Sistema Nacional NFS-e (SEFIN Nacional): DPS XML (leiaute v1.01, ns http://www.sped.fazenda.gov.br/nfse),
 * assinada (XMLDSig) com o A1 da empresa, GZip+Base64 em {"dpsXmlGZipB64": …}, POST /nfse com mTLS.
 * Pendente: montagem da DPS validada contra os XSD oficiais de produção restrita
 * (esquemas_prod_restrita_20261020.zip) — até lá, falha de forma explícita.
 */
class NacionalNfseEmissor implements NfseEmissor
{
    public function nome(): string
    {
        return 'nacional';
    }

    public function emitir(FinInvoice $nota, FinCompanySetting $empresa): NfseResultado
    {
        return new NfseResultado(false, mensagens: [[
            'codigo' => 'MINUTOR-PEND',
            'descricao' => 'Emissor nacional ainda não habilitado: aguardando leiaute oficial (XSD v1.01) e certificado A1 para homologação.',
        ]]);
    }
}
