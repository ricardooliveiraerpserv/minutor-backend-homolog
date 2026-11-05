<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Relatório de Horas Excedentes — {{ $clienteName }} — {{ $periodoLabel ?? $periodo }}</title>
  <style>
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
    @page { margin: 1.1cm 1.2cm; }
    @media screen { body { padding: 1.1cm 1.2cm; } }

    /* Cabeçalho roxo */
    table.bar { width: 100%; border-collapse: collapse; background: #6d28d9; border-radius: 8px; margin-bottom: 14px; }
    table.bar td { padding: 13px 16px; vertical-align: middle; color: #fff; }
    .bar h1 { font-size: 16px; margin: 0; color: #fff; }
    .bar .sub { font-size: 10.5px; color: #ede9fe; margin-top: 2px; }
    .bar .r { text-align: right; }

    .meta { margin: 2px 0 14px; font-size: 11px; color: #374151; }
    .meta b { color: #111827; }

    /* Cards KPI (tabela — DomPDF não tem flexbox) */
    table.cards { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 4px 0 12px; }
    table.cards td { width: 20%; border: 1px solid #e5e7eb; border-radius: 8px; padding: 9px 8px; vertical-align: top; background: #fafafa; }
    .card-lbl { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; }
    .card-val { font-size: 13px; font-weight: bold; color: #111827; margin-top: 3px; white-space: nowrap; }
    td.c-red   { background: #fef2f2; border-color: #fecaca; }
    td.c-red   .card-val { color: #dc2626; }
    td.c-pay   { background: #f5f3ff; border-color: #ddd6fe; }
    td.c-pay   .card-val { color: #6d28d9; }

    .note { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 9px 11px; margin: 10px 0 12px; font-size: 10.5px; color: #991b1b; }

    .proj-title { font-size: 12px; font-weight: bold; color: #1f2937; margin: 18px 0 4px; }
    .proj-title .tag { font-weight: normal; color: #6b7280; font-size: 10.5px; }

    table.rows { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    table.rows th { background: #f5f3ff; border-bottom: 1px solid #ddd6fe; text-align: left; padding: 5px 6px; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.03em; color: #6d28d9; }
    table.rows td { padding: 5px 6px; font-size: 10px; vertical-align: top; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }
    .exc { color: #dc2626; font-weight: bold; }
    .val { font-weight: bold; color: #111827; }
    .badge { background: #dc2626; color: #fff; border-radius: 3px; font-size: 7.5px; padding: 1px 4px; }
    .det { color: #4b5563; font-size: 9px; }
    .det .k { color: #6d28d9; font-weight: bold; }
    .det .s { font-weight: bold; }
    .det .i { color: #6b7280; }

    tfoot.ptot td { background: #f5f3ff; border-top: 2px solid #6d28d9; font-weight: bold; padding: 7px 6px; font-size: 11px; }

    table.total-box { margin-top: 18px; width: 100%; background: #6d28d9; border-radius: 6px; border-collapse: collapse; }
    table.total-box td { color: #fff; padding: 12px 16px; }
    .total-label { font-size: 12px; font-weight: bold; }
    .total-value { font-size: 18px; font-weight: bold; text-align: right; }

    .foot { margin-top: 14px; font-size: 8.5px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 7px; }
    .empty { padding: 16px; text-align: center; color: #9ca3af; }
  </style>
</head>
<body>

  <table class="bar">
    <tr>
      <td>
        <h1>Relatório de Horas Excedentes</h1>
        <div class="sub">ERPSERV &middot; Sustentação</div>
      </td>
      <td class="r">
        @if (!empty($logoDataUri))<img src="{{ $logoDataUri }}" alt="ERPSERV" style="height:26px;width:auto;margin-bottom:5px;">@endif
        <div class="sub">Emitido em {{ $emitidoEm }}</div>
      </td>
    </tr>
  </table>

  <div class="meta">
    <b>Cliente:</b> {{ $clienteName }} &nbsp;&middot;&nbsp; <b>Excedente acumulado</b> {{ $periodoLabel ?? '' }}
  </div>

  @forelse ($apontamentos as $ap)
    @php $it0 = $ap['itens'][0] ?? null; @endphp

    <div class="proj-title">{{ $ap['projeto'] }} <span class="tag">&mdash; {{ $ap['tipo'] }}</span></div>

    <table class="cards">
      <tr>
        <td><div class="card-lbl">Contratadas acumuladas</div><div class="card-val">{{ $ap['contratadas'] }}</div></td>
        <td><div class="card-lbl">Consumidas</div><div class="card-val">{{ $ap['consumido'] }}</div></td>
        <td class="c-red"><div class="card-lbl">Excedente</div><div class="card-val">{{ $ap['excedente_tot'] }}</div></td>
        <td><div class="card-lbl">Valor hora</div><div class="card-val">{{ $ap['valor_hora'] }}</div></td>
        <td class="c-pay"><div class="card-lbl">Valor a cobrar</div><div class="card-val">{{ $ap['valor_tot'] }}</div></td>
      </tr>
    </table>

    @if ($it0)
      <div class="note">
        <b>A partir do apontamento de {{ $it0['data'] }} ({{ $it0['consultor'] }}, {{ $it0['horas'] }}h)</b>
        o consumo acumulado ultrapassou as <b>{{ $ap['contratadas'] }}</b> contratadas. Deste apontamento em diante,
        os lançamentos abaixo compõem o excedente de <b>{{ $ap['excedente_tot'] }}</b>. Valor a cobrar = horas
        excedentes &times; {{ $ap['valor_hora'] }} (Hora Adicional do contrato).
      </div>
    @endif

    @if (!empty($ap['sem_hora_adic']))
      <div class="note" style="background:#fffbeb;border-color:#fde68a;color:#92400e;">
        <b>ATENÇÃO: a Hora Adicional deste contrato está R$ 0,00.</b> Defina a Hora Adicional para calcular o valor a cobrar — por ora o valor sai como R$ 0,00.
      </div>
    @endif
    @if (!empty($ap['resto_horas']))
      <div class="note" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;">
        @if (count($ap['itens']) > 0)
          <b>{{ $ap['resto_horas'] }}</b> do excedente vêm de blocos contratados / consumo migrado (sem apontamento individual). Os apontamentos abaixo compõem o restante.
        @else
          Todo o excedente (<b>{{ $ap['excedente_tot'] }}</b>) vem de blocos contratados / consumo migrado — não há apontamentos individuais a detalhar.
        @endif
      </div>
    @endif

    @if (count($ap['itens']) > 0)
    <table class="rows" style="table-layout:fixed;">
      <colgroup>
        <col style="width:13%;">
        <col style="width:auto;">
        <col style="width:10%;">
        <col style="width:11%;">
        <col style="width:15%;">
      </colgroup>
      <thead>
        <tr>
          <th>Data</th>
          <th>Consultor / Chamado / Interação</th>
          <th class="right">Horas</th>
          <th class="right">Exced.</th>
          <th class="right">Valor</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($ap['itens'] as $it)
          <tr>
            <td class="nowrap" style="border-bottom:none;padding-bottom:1px;">{{ $it['data'] }}</td>
            <td style="border-bottom:none;padding-bottom:1px;word-wrap:break-word;">
              {{ $it['consultor'] }}@if (!empty($it['estouro'])) <span class="badge">estourou aqui</span>@endif
            </td>
            <td class="right nowrap" style="border-bottom:none;padding-bottom:1px;">{{ $it['horas'] }}h</td>
            <td class="right nowrap exc" style="border-bottom:none;padding-bottom:1px;">{{ $it['excedente'] }}h</td>
            <td class="right nowrap val" style="border-bottom:none;padding-bottom:1px;">{{ $it['valor'] }}</td>
          </tr>
          <tr>
            <td></td>
            <td colspan="4" class="det" style="padding:0 6px 8px;border-bottom:1px solid #eef0f3;word-wrap:break-word;overflow-wrap:break-word;">
              @if (!empty($it['chamado']))<span class="k">Chamado {{ $it['chamado'] }}</span>@endif
              @if (!empty($it['solicitante'])) &middot; <span class="s">{{ $it['solicitante'] }}</span>@endif
              @if ($it['interacao'] !== '')<br><span class="i">{{ \Illuminate\Support\Str::limit($it['interacao'], 260) }}</span>@endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="empty">Nenhum apontamento compôs o excedente nesta competência.</td></tr>
        @endforelse
      </tbody>
      <tfoot class="ptot">
        <tr>
          <td colspan="3">TOTAL EXCEDENTE</td>
          <td class="right exc">{{ $ap['excedente_tot'] }}</td>
          <td class="right" style="color:#6d28d9;">{{ $ap['valor_tot'] }}</td>
        </tr>
      </tfoot>
    </table>
    @else
      <div style="font-size:11px;background:#f5f3ff;border-top:2px solid #6d28d9;padding:7px;margin:6px 0 4px;">
        TOTAL EXCEDENTE: <b style="color:#dc2626;">{{ $ap['excedente_tot'] }}</b> &nbsp;&middot;&nbsp; <b style="color:#6d28d9;">{{ $ap['valor_tot'] }}</b>
      </div>
    @endif
  @empty
    <div class="empty">Nenhuma hora excedente a cobrar na competência.</div>
  @endforelse

  @if (!empty($apontamentos))
    <table class="total-box">
      <tr>
        <td class="total-label">Total a cobrar — Horas Excedentes</td>
        <td class="total-value">{{ $totalFmt }}</td>
      </tr>
    </table>
  @endif

  <div class="foot">
    Horas consideram as horas faturáveis ao cliente, incluindo o consumo inicial migrado do sistema anterior quando houver.
    Solicitante e nº do chamado extraídos do Help Desk; interação = texto do apontamento. Relatório gerado automaticamente pelo Minutor &mdash; ERPSERV.
  </div>

</body>
</html>
