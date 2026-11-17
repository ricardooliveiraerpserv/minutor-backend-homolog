<?php

namespace App\Services\Financeiro\Billing;

/**
 * Contrato de um adaptador de cobrança (boleto + PIX). Cada conta bancária aponta para um
 * gateway (fin_bank_accounts.gateway). Implementações: FakeBillingGateway (DEV2/testes,
 * sem rede) e InterBillingGateway (API de Cobrança do Banco Inter, quando houver credenciais).
 */
interface BillingGateway
{
    /** Registra a cobrança no banco. Idempotente por $request->seuNumero. */
    public function registrar(ChargeRequest $request): ChargeResult;

    /** Consulta a situação atual da cobrança. */
    public function consultar(string $codigoSolicitacao): ChargeResult;

    /** Cancela (baixa) a cobrança no banco. */
    public function cancelar(string $codigoSolicitacao, string $motivo): ChargeResult;

    /** Nome curto do gateway (fake|inter|…), para logs e telas. */
    public function nome(): string;
}
