<?php
use App\Documents\DocumentAssets;
use Illuminate\Support\Facades\View;

$slides = [];
for ($i = 1; $i <= 10; $i++) { $u = DocumentAssets::dataUri(sprintf('slides/slide-%02d.svg', $i)); if ($u) $slides[] = $u; }

// Dados reais
$d = ['codigo' => 'ESP012-26', 'versao' => '01', 'cliente' => 'Indústria Acme do Brasil Ltda', 'cnpj' => '12.345.678/0001-90', 'executivo' => 'Ricardo Oliveira', 'data' => '17/06/2026'];

// OVERLAY CAPA (índice 0): cobre o bloco de campos (fundo roxo #530082) e redesenha rótulos brancos + valores teal.
$T = '#10AAA5';
$capa = <<<HTML
<div style="position:absolute;left:180px;top:426px;width:560px;height:128px;background:#530082"></div>
<div style="position:absolute;left:180px;top:430px;font-size:18px;line-height:1.46;color:#fff;font-family:Calibri,'Segoe UI',Arial,sans-serif;letter-spacing:.2px">
  ID: <span style="color:{$T}">{$d['codigo']}</span> Versão: <span style="color:{$T}">{$d['versao']}</span><br>
  CLIENTE: <span style="color:{$T}">{$d['cliente']}</span><br>
  CNPJ: <span style="color:{$T}">{$d['cnpj']}</span><br>
  EXECUTIVO: <span style="color:{$T}">{$d['executivo']}</span><br>
  DATA: <span style="color:{$T}">{$d['data']}</span>
</div>
HTML;

$overlays = [0 => $capa];
$html = View::make('pdf.documents.proposta.render', ['codigo' => $d['codigo'], 'slides' => $slides, 'overlays' => $overlays])->render();
file_put_contents('/tmp/visual-extract/ov.html', $html);
echo "HTML ov gerado\n";
