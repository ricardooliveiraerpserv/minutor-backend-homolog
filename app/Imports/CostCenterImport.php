<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import simples de centro de custo: colunas `codigo` e `descricao` (cabeçalho na 1ª linha).
 * Não persiste sozinho — só captura as linhas normalizadas para o controller tratar.
 */
class CostCenterImport implements ToArray, WithHeadingRow
{
    public array $rows = [];

    public function array(array $array): void
    {
        $this->rows = $array;
    }
}
