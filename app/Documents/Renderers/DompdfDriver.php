<?php

namespace App\Documents\Renderers;

use App\Documents\Contracts\RenderDriver;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Motor LEGADO — fechamentos simples já existentes. NÃO usar para propostas (sem flex/grid).
 */
class DompdfDriver implements RenderDriver
{
    public function name(): string
    {
        return 'dompdf';
    }

    public function render(string $html, array $opts = []): string
    {
        $paper  = $opts['paper'] ?? 'a4';
        $orient = $opts['orientation'] ?? 'portrait';
        return Pdf::loadHTML($html)->setPaper($paper, $orient)->output();
    }
}
