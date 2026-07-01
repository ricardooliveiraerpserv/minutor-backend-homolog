<?php

namespace App\Documents\Contracts;

/**
 * Contrato único de um motor de render. Trocar de motor = trocar o driver,
 * sem alterar os consumidores (DocumentService, módulos).
 */
interface RenderDriver
{
    /** Recebe HTML pronto e devolve os bytes do PDF. */
    public function render(string $html, array $opts = []): string;

    /** Nome do driver (chromium|dompdf). */
    public function name(): string;
}
