<?php

namespace App\Services\Financeiro\Billing;

/** Resultado normalizado de qualquer gateway. */
final class ChargeResult
{
    public const REGISTRADA = 'registrada';
    public const PAGA = 'paga';
    public const CANCELADA = 'cancelada';
    public const VENCIDA = 'vencida';

    public function __construct(
        public readonly string $codigoSolicitacao,
        public readonly string $situacao,
        public readonly ?string $nossoNumero = null,
        public readonly ?string $linhaDigitavel = null,
        public readonly ?string $codigoBarras = null,
        public readonly ?string $pixCopiaECola = null,
        public readonly ?string $valorPago = null,
        public readonly ?string $dataPagamento = null, // Y-m-d
        public readonly bool $simulado = false,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
