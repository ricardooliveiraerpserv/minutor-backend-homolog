<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Relatório de Horas Excedentes — {{ $clienteName }} — {{ $periodo }}</title>
  <style>
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
    @page { margin: 1.3cm 1.5cm; }
    @media screen { body { padding: 1.3cm 1.5cm; } }

    table.header { width: 100%; border-collapse: collapse; border-bottom: 2px solid #0e7490; margin-bottom: 18px; }
    table.header td { padding: 0 0 14px; vertical-align: middle; }
    .hd-logo { width: 50%; }
    .hd-logo img { width: 150px; max-width: 100%; height: auto; }
    .brand { font-size: 20px; font-weight: bold; color: #0e7490; }
    .hd-meta { text-align: right; }
    .doc-title { font-size: 18px; font-weight: bold; color: #1f2937; }
    .doc-sub { font-size: 11px; color: #6b7280; margin: 1px 0 6px; }
    .doc-line { font-size: 11px; color: #4b5563; margin-top: 1px; }
    .doc-line .lbl { font-weight: bold; color: #374151; }
    .doc-emit { font-size: 10px; color: #9ca3af; margin-top: 3px; }

    .summary { width: 100%; margin: 14px 0 18px; border-collapse: collapse; }
    .summary td { width: 50%; background: #ecfeff; border: 1px solid #a5f0f7; padding: 10px 12px; vertical-align: top; }
    .summary-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.06em; color: #0e7490; font-weight: bold; }
    .summary-value { font-size: 15px; font-weight: bold; color: #111827; margin-top: 3px; }

    table.rows { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.rows th { background: #ecfeff; border-bottom: 1px solid #a5f0f7; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #0e7490; }
    table.rows td { border-bottom: 1px solid #f3f4f6; padding: 5px 6px; font-size: 10px; vertical-align: top; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }

    .total-box { margin-top: 22px; width: 100%; background: #0e7490; color: #fff; border-radius: 6px; border-collapse: collapse; }
    .total-box td { color: #fff; padding: 12px 16px; }
    .total-label { font-size: 12px; font-weight: bold; }
    .total-value { font-size: 18px; font-weight: bold; text-align: right; }
    .empty { padding: 20px; text-align: center; color: #9ca3af; }
  </style>
</head>
<body>
  <table class="header">
    <tr>
      <td class="hd-logo">
        @if ($logoDataUri)
          <img src="{{ $logoDataUri }}" alt="ERPSERV">
        @else
          <span class="brand">ERPSERV</span>
        @endif
      </td>
      <td class="hd-meta">
        <div class="doc-title">Relatório de Horas Excedentes</div>
        <div class="doc-sub">Horas consumidas acima das contratadas</div>
        <div class="doc-line"><span class="lbl">Cliente:</span> {{ $clienteName }}</div>
        <div class="doc-line"><span class="lbl">Competência:</span> {{ $periodo }}</div>
        <div class="doc-emit">Emitido em {{ $emitidoEm }}</div>
      </td>
    </tr>
  </table>

  <table class="summary">
    <tr>
      <td>
        <div class="summary-label">Contratos com excedente</div>
        <div class="summary-value">{{ $qtd }}</div>
      </td>
      <td>
        <div class="summary-label">Total a cobrar</div>
        <div class="summary-value">{{ $totalFmt }}</div>
      </td>
    </tr>
  </table>

  <table class="rows">
    <thead>
      <tr>
        <th>Contrato / Projeto</th>
        <th>Tipo</th>
        <th class="right">Contratadas</th>
        <th class="right">Consumido</th>
        <th class="right">Excedente</th>
        <th class="right">Hora adic.</th>
        <th class="right">Valor</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($linhas as $l)
        <tr>
          <td>{{ $l['projeto'] }}</td>
          <td class="nowrap">{{ $l['tipo'] }}</td>
          <td class="right nowrap">{{ $l['contratadas'] }}</td>
          <td class="right nowrap">{{ $l['consumido'] }}</td>
          <td class="right nowrap">{{ $l['excedente'] }}</td>
          <td class="right nowrap">{{ $l['hora_adic'] }}</td>
          <td class="right nowrap">{{ $l['valor'] }}</td>
        </tr>
      @empty
        <tr><td colspan="7" class="empty">Nenhuma hora excedente a cobrar na competência.</td></tr>
      @endforelse
    </tbody>
  </table>

  <table class="total-box">
    <tr>
      <td class="total-label">Total a cobrar — Horas Excedentes</td>
      <td class="total-value">{{ $totalFmt }}</td>
    </tr>
  </table>

  @if (!empty($apontamentos))
    <h2 style="font-size:13px;color:#0e7490;margin:24px 0 4px;">Apontamentos da competência</h2>
    <p class="doc-sub" style="margin:0 0 8px;">Detalhamento dos apontamentos que compõem o consumo apurado.</p>
    @foreach ($apontamentos as $ap)
      <p style="font-size:11px;font-weight:bold;color:#1f2937;margin:14px 0 4px;">
        {{ $ap['projeto'] }} <span style="font-weight:normal;color:#6b7280;">— {{ $ap['total_horas'] }}</span>
      </p>
      <table class="rows">
        <thead>
          <tr>
            <th>Data</th>
            <th>Consultor</th>
            <th>Descrição</th>
            <th class="right">Horas</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($ap['itens'] as $it)
            <tr>
              <td class="nowrap">{{ $it['data'] }}</td>
              <td>{{ $it['consultor'] }}</td>
              <td>{{ $it['descricao'] }}</td>
              <td class="right nowrap">{{ $it['horas'] }}h</td>
            </tr>
          @empty
            <tr><td colspan="4" class="empty">Sem apontamentos na competência.</td></tr>
          @endforelse
        </tbody>
      </table>
    @endforeach
  @endif
</body>
</html>
