<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Excel do relatório de Horas Excedentes — MESMOS DADOS do PDF: título + resumo por contrato
 * (Contratadas/Consumidas/Excedente/Valor hora/Valor a cobrar) + detalhamento (ponto de estouro:
 * Data/Consultor/Chamado/Solicitante/Interação/Horas/Excedente/Valor) + total geral. Recebe o
 * mesmo viewData do PDF.
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
        $out[] = ['Cliente:', $d['clienteName'] ?? '', '', 'Excedente acumulado:', $d['periodoLabel'] ?? '', '', 'Emitido em:', $d['emitidoEm'] ?? ''];
        $out[] = [];

        foreach (($d['apontamentos'] ?? []) as $ap) {
            // Resumo do contrato (espelha os cards do PDF).
            $out[] = [$ap['projeto'] . '  (' . ($ap['tipo'] ?? '') . ')'];
            $out[] = ['Contratadas', 'Consumidas', 'Excedente', 'Valor hora', 'Valor a cobrar'];
            $out[] = [
                $ap['contratadas'] ?? '',
                $ap['consumido'] ?? '',
                $ap['excedente_tot'] ?? '',
                $ap['valor_hora'] ?? '',
                $ap['valor_tot'] ?? '',
            ];
            $out[] = [];

            // Detalhamento (ponto de estouro).
            $out[] = ['Data', 'Consultor', 'Chamado', 'Solicitante', 'Interação', 'Horas', 'Excedente', 'Valor'];
            foreach (($ap['itens'] ?? []) as $it) {
                $out[] = [
                    $it['data'],
                    $it['consultor'],
                    $it['chamado'] ?? '',
                    $it['solicitante'] ?? '',
                    $it['interacao'] ?? '',
                    $it['horas'],
                    $it['excedente'] ?? '',
                    $it['valor'] ?? '',
                ];
            }
            $out[] = [];
        }

        $out[] = ['TOTAL A COBRAR', '', '', '', '', '', '', $d['totalFmt'] ?? ''];

        return $out;
    }
}
