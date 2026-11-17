<?php

namespace App\Services\Financeiro\Billing;

/**
 * Banco Inter — API de Cobrança v3 (boleto + PIX, mTLS + OAuth2 client_credentials).
 * Placeholder até as credenciais chegarem (F4): falha de forma explícita, sem chamar rede.
 * Credenciais esperadas em fin_bank_accounts.gateway_credenciais (cifrado):
 * client_id, client_secret, certificado (crt), chave (key), conta_corrente.
 */
class InterBillingGateway implements BillingGateway
{
    public function __construct(private readonly ?array $credenciais) {}

    public function nome(): string
    {
        return 'inter';
    }

    public function registrar(ChargeRequest $request): ChargeResult
    {
        $this->naoDisponivel();
    }

    public function consultar(string $codigoSolicitacao): ChargeResult
    {
        $this->naoDisponivel();
    }

    public function cancelar(string $codigoSolicitacao, string $motivo): ChargeResult
    {
        $this->naoDisponivel();
    }

    private function naoDisponivel(): never
    {
        throw new BillingGatewayException(empty($this->credenciais)
            ? 'Banco Inter sem credenciais cadastradas nesta conta.'
            : 'Integração com o Banco Inter ainda não implementada (fase F4).');
    }
}
