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
        $out = [];

        $out[] = ['Relatório de Horas Excedentes'];
        $out[] = ['Cliente', $d['clienteName'] ?? ''];
        $out[] = ['Competência', $d['periodo'] ?? ''];
        $out[] = ['Emitido em', $d['emitidoEm'] ?? ''];
        $out[] = [];

        $out[] = ['Contrato / Projeto', 'Tipo', 'Contratadas', 'Consumido', 'Excedente', 'Hora adic.', 'Valor'];
        foreach (($d['linhas'] ?? []) as $l) {
            $out[] = [$l['projeto'], $l['tipo'], $l['contratadas'], $l['consumido'], $l['excedente'], $l['hora_adic'], $l['valor']];
        }
        $out[] = ['', '', '', '', '', 'Total a cobrar', $d['totalFmt'] ?? ''];
        $out[] = [];

        if (!empty($d['apontamentos'])) {
            $out[] = ['Apontamentos da competência'];
            foreach ($d['apontamentos'] as $ap) {
                $out[] = [];
                $out[] = [$ap['projeto'] . ' — ' . $ap['total_horas']];
                $out[] = ['Data', 'Consultor', 'Descrição', 'Horas'];
                foreach ($ap['itens'] as $it) {
                    $out[] = [$it['data'], $it['consultor'], $it['descricao'], $it['horas']];
                }
            }
        }

        return $out;
    }
}
