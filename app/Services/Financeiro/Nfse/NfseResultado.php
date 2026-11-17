<?php

namespace App\Services\Financeiro\Nfse;

final class NfseResultado
{
    public function __construct(
        public readonly bool $autorizada,
        public readonly ?string $numero = null,
        public readonly ?string $chaveAcesso = null,
        public readonly ?string $codigoVerificacao = null,
        public readonly ?string $xmlDps = null,
        public readonly ?string $xmlNfse = null,
        /** @var array<int, array{codigo?:string, descricao:string}> */
        public readonly array $mensagens = [],
        /** true = falha de comunicação/indefinida (pode ter sido emitida): exige conferência antes de reenviar */
        public readonly bool $indefinido = false,
    ) {}
}
