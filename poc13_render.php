<?php
use App\Documents\{CrmProposalCalcService, DocumentRenderer};
use Illuminate\Support\Facades\View;

$brl = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
$calc = app(CrmProposalCalcService::class)->compute(
    ['horas_consultoria' => 100, 'custo_h_consultoria' => 80, 'custo_h_coordenacao' => 72, 'venda_h' => 174, 'valor_hora_cliente' => 170],
    'por_hora'
);
$horas = 100; $vhCliente = 170;
$data = [
    'codigo' => 'ESP012-26', 'versao' => 1, 'status' => 'em_elaboracao',
    'tipo_label' => 'BANCO DE HORAS FIXO',
    'cliente' => 'Indústria Acme do Brasil Ltda', 'cnpj' => '12.345.678/0001-90', 'executivo' => 'Ricardo Oliveira',
    'gerado_em' => '17/06/2026',
    'horas' => $horas, 'valor_hora' => $brl($vhCliente), 'faturamento' => $brl($horas * $vhCliente),
    'margem_pct' => number_format($calc['margem_pct'] * 100, 1, ',', '.') . '%',
    'atividades' => ['Diagnóstico de ambiente', 'Consultoria de processos', 'Sustentação', 'Manutenção', 'Desenvolvimentos', 'Gerenciamento de Projetos'],
    'parcelas' => [['parc' => '2x', 'valor' => '50% em cada parcela', 'venc' => '10 / 40 dias após assinatura']],
    'duracao_meses' => 12, 'excedente' => $brl(190), 'despesa_sp' => $brl(170), 'despesa_fora' => $brl(250),
];

$html = View::make('pdf.documents.proposta.modulos.banco-horas-fixo', $data)->render();
file_put_contents('/tmp/poc-render/proposta-prod.html', $html);
echo 'HTML produção: ' . strlen($html) . " bytes (data-URI embutido: " . (str_contains($html, 'data:image/jpeg;base64') ? 'SIM' : 'NAO') . ")\n";

// Render REAL via Gotenberg (motor oficial) — prova que não há file:// e o PDF sai
$pdf = app(DocumentRenderer::class)->render('pdf.documents.proposta.modulos.banco-horas-fixo', $data, 'chromium', ['paperWidth' => 13.333, 'paperHeight' => 7.5, 'preferCssPageSize' => true]);
file_put_contents('/tmp/poc-render/proposta-prod.pdf', $pdf);
echo 'PDF Gotenberg: ' . (str_starts_with($pdf, '%PDF') ? 'VÁLIDO' : 'INVÁLIDO') . ' ' . (int) round(strlen($pdf) / 1024) . "KB\n";
