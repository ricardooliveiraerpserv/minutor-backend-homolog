<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Fechamento Diretoria - {{ $periodo }} | {{ $diretorNome }}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
    @page { margin: 1.3cm 1.2cm; }
    @media screen { body { padding: 1.3cm 1.2cm; } }
    .header { border-bottom: 2px solid #7c3aed; padding-bottom: 12px; margin-bottom: 16px; }
    .header table { width: 100%; border-collapse: collapse; }
    .logo img { height: 42px; width: auto; }
    .meta { text-align: right; font-size: 11px; color: #4b5563; line-height: 1.5; }
    .meta strong { font-size: 15px; color: #111827; display: block; margin-bottom: 3px; }
    .brand { font-size: 18px; font-weight: bold; color: #111827; }
    .brand-sub { font-size: 10px; color: #6b7280; margin-top: 2px; }

    .summary { width: 100%; margin: 14px 0 18px; border-collapse: collapse; }
    .summary td { background: #f5f3ff; border: 1px solid #ede9fe; padding: 9px 11px; vertical-align: top; width: 33.33%; }
    .summary-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; color: #7c3aed; font-weight: bold; }
    .summary-value { font-size: 14px; font-weight: bold; color: #111827; margin-top: 3px; }

    .group-title { font-size: 12px; font-weight: bold; color: #5b21b6; background: #ede9fe; padding: 6px 10px; margin-top: 14px; border-radius: 4px; }

    table.rows { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.rows th { background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; padding: 5px 7px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; }
    table.rows td { border-bottom: 1px solid #f3f4f6; padding: 5px 7px; font-size: 10px; }
    .right { text-align: right; }
    .neg { color: #dc2626; font-weight: bold; }
    .row-total td { border-top: 2px solid #e5e7eb; font-weight: bold; background: #f9fafb; }
    .row-liq td { background: #ecfdf5; font-weight: bold; color: #065f46; }

    .total-box { margin-top: 22px; background: #7c3aed; color: #fff; padding: 12px 16px; border-radius: 6px; width: 100%; border-collapse: collapse; }
    .total-box td { color: #fff; }
    .total-label { font-size: 12px; font-weight: bold; }
    .total-value { font-size: 18px; font-weight: bold; text-align: right; }
  </style>
</head>
<body>
  <div class="header">
    <table>
      <tr>
        <td style="vertical-align:middle;">
          @if(!empty($logoDataUri))
            <span class="logo"><img src="{{ $logoDataUri }}" alt="ERPSERV Consultoria"></span>
          @else
            <span class="brand">ERPSERV Consultoria</span><div class="brand-sub">Minutor — Controle de horas e contratos</div>
          @endif
        </td>
        <td class="meta" style="vertical-align:middle;">
          <strong>{{ $diretorNome }}</strong>
          Fechamento Diretoria &nbsp;·&nbsp; {{ $periodo }}
          @if(!empty($cooperativa))<br>{{ $cooperativa }}@endif
        </td>
      </tr>
    </table>
  </div>

  {{-- Resumo --}}
  <table class="summary">
    <tr>
      <td>
        <div class="summary-label">Total</div>
        <div class="summary-value">{{ $totalFmt }}</div>
      </td>
      <td>
        <div class="summary-label">Taxa + INSS</div>
        <div class="summary-value">{{ $taxaFmt }}</div>
      </td>
      <td>
        <div class="summary-label">Valor Líquido a Receber</div>
        <div class="summary-value" style="color:#065f46;">{{ $liquidoFmt }}</div>
      </td>
    </tr>
  </table>

  {{-- Lançamentos --}}
  <table class="rows">
    <thead>
      <tr><th>Descrição</th><th class="right" style="width:160px;">Valor</th></tr>
    </thead>
    <tbody>
      @foreach($lancamentos as $l)
        <tr>
          <td>{{ $l['descricao'] }}</td>
          <td class="right @if($l['neg'])neg @endif">{{ $l['valorFmt'] }}</td>
        </tr>
      @endforeach
      @if($temTaxa)
        <tr><td>Taxa + INSS</td><td class="right">{{ $taxaFmt }}</td></tr>
      @endif
      <tr class="row-total"><td class="right">TOTAL</td><td class="right">{{ $totalFmt }}</td></tr>
      <tr class="row-liq"><td class="right">VALOR LÍQUIDO A RECEBER</td><td class="right">{{ $liquidoFmt }}</td></tr>
    </tbody>
  </table>

  {{-- Divisão por cooperativa --}}
  @if($temCoop)
    <div class="group-title">Divisão por cooperativa</div>
    <table class="rows">
      <thead>
        <tr><th>Cooperativa</th><th class="right" style="width:130px;">Valor a Receber</th><th class="right" style="width:110px;">Taxa</th><th class="right" style="width:130px;">Produção</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>ERPSERV</td><td class="right">{{ $coopErpValorFmt }}</td><td class="right">{{ $coopErpTaxaFmt }}</td><td class="right">{{ $coopErpProdFmt }}</td>
        </tr>
        <tr>
          <td>BIZIFY</td><td class="right">{{ $coopBizValorFmt }}</td><td class="right">{{ $coopBizTaxaFmt }}</td><td class="right">{{ $coopBizProdFmt }}</td>
        </tr>
      </tbody>
    </table>
  @endif

  <table class="total-box">
    <tr>
      <td class="total-label">VALOR LÍQUIDO A RECEBER — {{ strtoupper($diretorNome) }}</td>
      <td class="total-value">{{ $liquidoFmt }}</td>
    </tr>
  </table>
</body>
</html>
