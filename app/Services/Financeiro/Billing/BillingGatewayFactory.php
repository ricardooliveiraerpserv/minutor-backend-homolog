<?php

namespace App\Services\Financeiro\Billing;

use App\Models\Financeiro\FinBankAccount;

/**
 * Resolve o adaptador de cobrança de uma conta bancária.
 * Trava de segurança: o gateway SIMULADO é recusado em produção, salvo se
 * FIN_ALLOW_FAKE_BILLING=true for setado explicitamente.
 */
class BillingGatewayFactory
{
    public function paraConta(FinBankAccount $conta): BillingGateway
    {
        return match ($conta->gateway) {
            'fake' => $this->fake(),
            'inter' => new InterBillingGateway($conta->gateway_credenciais),
            default => throw new BillingGatewayException("A conta \"{$conta->nome}\" não emite cobrança (gateway: {$conta->gateway})."),
        };
    }

    public function fake(): FakeBillingGateway
    {
        if (app()->environment('production') && ! config('financeiro.allow_fake_billing')) {
            throw new BillingGatewayException('Gateway de cobrança simulado não é permitido em produção.');
        }
        return app(FakeBillingGateway::class);
    }
}
