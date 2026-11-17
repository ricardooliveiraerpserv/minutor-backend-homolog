<?php

namespace App\Services\Financeiro\Nfse;

use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinInvoice;

/**
 * Emissor de NFS-e. Implementações: SimuladoNfseEmissor (DEV2/testes, sem rede, só homologação)
 * e NacionalNfseEmissor (API do Sistema Nacional NFS-e, mTLS com o certificado A1 da empresa).
 */
interface NfseEmissor
{
    /**
     * Emite a NFS-e da nota (já com numero_dps/serie reservados). Não persiste nada:
     * devolve o resultado para o serviço de emissão gravar.
     */
    public function emitir(FinInvoice $nota, FinCompanySetting $empresa): NfseResultado;

    public function nome(): string;
}
