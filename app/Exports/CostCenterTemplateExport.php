<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Modelo (template) de importação de centro de custo: cabeçalho `codigo`, `descricao`.
 */
class CostCenterTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['codigo', 'descricao'];
    }

    public function array(): array
    {
        return [
            ['CC001', 'Exemplo — Centro de Custo A'],
            ['CC002', 'Exemplo — Centro de Custo B'],
        ];
    }
}
