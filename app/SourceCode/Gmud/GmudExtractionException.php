<?php

namespace App\SourceCode\Gmud;

/**
 * Falha ESTRUTURAL/de SEGURANÇA da extração do ZIP (corrompido, vazio, bomba de descompressão,
 * limites de nº/tamanho excedidos). Distinta de um arquivo individual pulado (junk/traversal), que
 * é apenas ignorado. Ao ser lançada, o job marca o pacote como `failed` — nada é publicado.
 */
class GmudExtractionException extends \RuntimeException
{
    public function __construct(public string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
