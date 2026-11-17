<?php

namespace App\Services\Financeiro\Billing;

use Carbon\CarbonInterface;

/** Dados para registrar um boleto + PIX. Valores em reais (2 casas). */
final class ChargeRequest
{
    public function __construct(
        public readonly string $seuNumero,          // identificador nosso (ex.: id do título) — chave de idempotência
        public readonly string $valor,              // decimal em string, ex.: "7292.14"
        public readonly CarbonInterface $vencimento,
        public readonly string $pagadorNome,
        public readonly string $pagadorDocumento,   // CPF/CNPJ só dígitos
        public readonly ?string $pagadorEmail = null,
        public readonly ?array $pagadorEndereco = null, // cep, logradouro, numero, bairro, cidade, uf
        public readonly ?string $mensagem = null,
        public readonly ?string $multaPercentual = null,
        public readonly ?string $jurosMensalPercentual = null,
    ) {
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $valor) || (float) $valor <= 0) {
            throw new \InvalidArgumentException('Valor da cobrança inválido.');
        }
        if (trim($seuNumero) === '') {
            throw new \InvalidArgumentException('seuNumero obrigatório.');
        }
    }
}
