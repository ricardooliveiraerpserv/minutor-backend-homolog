<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Excel do relatório de Horas Excedentes: resumo por contrato + apontamentos
 * (detalhamento) da competência apurada. Recebe o mesmo viewData do PDF.
 */
class ExcedenteExport implements FromArray, WithTitle
{
    public function __construct(private array $data) {}

    public function title(): string
    {
        return 'Horas Excedentes';
    }

    public function array(): array
    {
        $d = $this->data;

        // Só a tabela de apontamentos: cabeçalho das colunas + linhas (sem título/resumo).
        $out = [['Data', 'Consultor', 'Projeto', 'Descrição', 'Horas']];
        foreach (($d['apontamentos'] ?? []) as $ap) {
            foreach ($ap['itens'] as $it) {
                $out[] = [$it['data'], $it['consultor'], $ap['projeto'], $it['descricao'], $it['horas']];
            }
        }

        return $out;
    }
}
