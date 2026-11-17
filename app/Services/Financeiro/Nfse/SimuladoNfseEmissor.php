<?php

namespace App\Services\Financeiro\Nfse;

use App\Models\Financeiro\FinCompanySetting;
use App\Models\Financeiro\FinInvoice;

/**
 * NFS-e SIMULADA: não fala com governo nenhum. Gera número e chave fictícios (prefixo SIM) para
 * testar o fluxo parcela → nota → título na DEV2 enquanto o certificado A1 e a homologação na API
 * nacional não estão prontos. Recusada em ambiente de produção da NFS-e (ver EmissaoService).
 */
class SimuladoNfseEmissor implements NfseEmissor
{
    public function nome(): string
    {
        return 'simulado';
    }

    public function emitir(FinInvoice $nota, FinCompanySetting $empresa): NfseResultado
    {
        if ((float) $nota->valor_servico <= 0) {
            return new NfseResultado(false, mensagens: [['codigo' => 'SIM001', 'descricao' => 'Valor do serviço deve ser maior que zero.']]);
        }
        $chave = 'SIM' . str_pad((string) $nota->company_id, 4, '0', STR_PAD_LEFT)
            . str_pad((string) $nota->serie, 5, '0', STR_PAD_LEFT) . str_pad((string) $nota->numero_dps, 15, '0', STR_PAD_LEFT);

        return new NfseResultado(
            autorizada: true,
            numero: 'SIM-' . $nota->numero_dps,
            chaveAcesso: $chave,
            codigoVerificacao: strtoupper(substr(hash('sha256', $chave), 0, 8)),
            mensagens: [['codigo' => 'SIM', 'descricao' => 'NFS-e SIMULADA — sem valor fiscal.']],
        );
    }
}
