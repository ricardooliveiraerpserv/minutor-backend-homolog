<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Relatório de Serviço — {{ $ticket_number }}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
    @page { margin: 1.3cm 1.2cm 1.6cm; }
    @media screen { body { padding: 1.3cm 1.2cm; } }

    .header { border-bottom: 2px solid {{ $brand }}; padding-bottom: 12px; margin-bottom: 16px; }
    .header table { width: 100%; border-collapse: collapse; }
    .logo img { height: 40px; width: auto; }
    .doc-title { text-align: right; }
    .doc-title .kind { font-size: 9px; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; }
    .doc-title .num { font-size: 20px; font-weight: bold; color: #111827; }
    .doc-title .sub { font-size: 10px; color: #6b7280; margin-top: 2px; }

    .subject { font-size: 15px; font-weight: bold; color: #111827; margin: 4px 0 12px; }

    .meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; table-layout: fixed; }
    .meta td { border: 1px solid #eee; padding: 7px 9px; vertical-align: top; width: 25%; }
    .meta .lbl { font-size: 8px; text-transform: uppercase; letter-spacing: 0.04em; color: #9ca3af; font-weight: bold; }
    .meta .val { font-size: 10px; font-weight: bold; color: #111827; margin-top: 2px; }

    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: bold; }

    .section { font-size: 11px; font-weight: bold; color: #fff; background: {{ $brand }}; padding: 6px 10px; margin: 16px 0 8px; border-radius: 4px; }

    .sla { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    .sla td { border: 1px solid #eee; padding: 7px 9px; width: 50%; vertical-align: top; }
    .sla .lbl { font-size: 8px; text-transform: uppercase; color: #9ca3af; font-weight: bold; }
    .sla .val { font-size: 11px; font-weight: bold; margin-top: 2px; }

    .desc { background: #f9fafb; border: 1px solid #eef0f3; border-radius: 5px; padding: 9px 11px; font-size: 10px; line-height: 1.5; word-wrap: break-word; }
    .desc img, .card .body img { max-width: 100%; }

    .inter { width: 100%; border-collapse: collapse; }
    .inter .row td { padding: 0 0 10px; vertical-align: top; }
    .card { border: 1px solid #e6e8ec; border-radius: 6px; overflow: hidden; }
    .card .top { padding: 6px 10px; border-bottom: 1px solid #eef0f3; }
    .card .top table { width: 100%; border-collapse: collapse; }
    .who { font-size: 10px; font-weight: bold; color: #111827; }
    .role { font-size: 8px; font-weight: bold; padding: 1px 6px; border-radius: 8px; margin-left: 5px; }
    .role.ag { background: #ede9fe; color: #6d28d9; }
    .role.cl { background: #e0f2fe; color: #0369a1; }
    .when { font-size: 9px; color: #9ca3af; text-align: right; }
    .card .body { padding: 8px 10px; font-size: 10px; line-height: 1.5; color: #374151; word-wrap: break-word; }
    .effort { display: inline-block; font-size: 11px; font-weight: bold; color: #5b21b6; background: #ede9fe; border-radius: 5px; padding: 4px 10px; margin-top: 10px; }
    .sol { background: #ecfdf5; }
    .sol .top { border-bottom-color: #d1fae5; }
    .int { background: #fffbeb; }
    .int .top { border-bottom-color: #fef3c7; }
    .role.int-badge { background: #fef3c7; color: #92400e; }

    .signature { margin-top: 14px; padding-top: 12px; border-top: 1px solid #eef0f3; }

    .hours { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .hours th { background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; padding: 5px 8px; font-size: 8px; text-transform: uppercase; color: #6b7280; }
    .hours td { border-bottom: 1px solid #f3f4f6; padding: 5px 8px; font-size: 10px; }
    .hours .r { text-align: right; font-variant-numeric: tabular-nums; }
    .hours .tot td { font-weight: bold; color: {{ $brand }}; border-top: 1px solid #e5e7eb; }

    .muted { color: #9ca3af; }
    .footer { position: fixed; bottom: -1cm; left: 0; right: 0; font-size: 8px; color: #9ca3af; text-align: center; border-top: 1px solid #eee; padding-top: 4px; }
  </style>
</head>
<body>
  <div class="header">
    <table><tr>
      <td class="logo" style="width:60%">@if($logo)<img src="{{ $logo }}" alt="ERPSERV">@else<span style="font-size:18px;font-weight:bold;color:#111827">ERPSERV</span>@endif</td>
      <td class="doc-title">
        <div class="kind">Relatório de Serviço</div>
        <div class="num">{{ $ticket_number }}</div>
        <div class="sub">Emitido em {{ $generated_at }}</div>
      </td>
    </tr></table>
  </div>

  <div class="subject">{{ $subject }}</div>

  <table class="meta">
    <tr>
      <td><div class="lbl">Status</div><div class="val"><span class="badge" style="background:{{ $status_bg }};color:{{ $status_fg }}">{{ $status }}</span></div></td>
      <td><div class="lbl">Prioridade</div><div class="val">{{ $priority }}</div></td>
      <td><div class="lbl">Canal</div><div class="val">{{ $channel }}</div></td>
      <td><div class="lbl">Categoria</div><div class="val">{{ $category }}</div></td>
    </tr>
    <tr>
      <td><div class="lbl">Cliente</div><div class="val">{{ $customer }}</div></td>
      <td><div class="lbl">Solicitante</div><div class="val">{{ $requester }}</div></td>
      <td><div class="lbl">Responsável</div><div class="val">{{ $assignee }}</div></td>
      <td><div class="lbl">Equipe</div><div class="val">{{ $team }}</div></td>
    </tr>
    <tr>
      <td><div class="lbl">Aberto em</div><div class="val">{{ $opened_at }}</div></td>
      <td><div class="lbl">1ª resposta</div><div class="val">{{ $first_at }}</div></td>
      <td><div class="lbl">Resolvido em</div><div class="val">{{ $resolved_at }}</div></td>
      <td><div class="lbl">Tempo de vida</div><div class="val">{{ $life }}</div></td>
    </tr>
  </table>

  @if($sla)
  <div class="section">SLA</div>
  <table class="sla"><tr>
    <td><div class="lbl">Política</div><div class="val" style="color:#111827">{{ $sla['policy'] }}</div></td>
    <td><div class="lbl">Prazo de resolução</div><div class="val" style="color:{{ $sla['res_color'] }}">{{ $sla['res'] }}</div></td>
  </tr></table>
  @endif

  <div class="section">Histórico de atendimento ({{ count($interactions) }})</div>
  @if(count($interactions))
  <table class="inter">
    @foreach($interactions as $it)
    <tr class="row"><td>
      <div class="card {{ $it['solution'] ? 'sol' : '' }} {{ $it['internal'] ? 'int' : '' }}">
        <div class="top"><table><tr>
          <td><span class="who">{{ $it['who'] }}</span><span class="role {{ $it['is_agent'] ? 'ag' : 'cl' }}">{{ $it['is_agent'] ? 'Equipe' : 'Cliente' }}</span>@if($it['internal'])<span class="role int-badge">Interna</span>@endif
          @if($it['solution'])<span class="role ag" style="background:#d1fae5;color:#047857">Solução</span>@endif</td>
          <td class="when">{{ $it['when'] }}</td>
        </tr></table></div>
        <div class="body">{!! $it['body'] ?: '—' !!}@if($it['effort'])<div class="effort">Tempo apontado: {{ $it['effort'] }}</div>@endif</div>
      </div>
    </td></tr>
    @endforeach
  </table>
  @else
  <div class="desc muted">Sem interações registradas.</div>
  @endif

  {{-- Descrição de abertura por último: é a interação mais antiga do chamado. --}}
  <div class="section">Descrição / Abertura</div>
  <div class="desc">{!! $description ?: '—' !!}</div>

  @if($with_apontamentos)
  <div class="section">Apontamentos de horas</div>
  @if(count($apontamentos))
  <table class="hours">
    <tr><th>Data</th><th>Consultor</th><th>Intervalo</th><th class="r">Duração</th><th>Cobrável</th></tr>
    @foreach($apontamentos as $a)
    <tr>
      <td>{{ $a['date'] }}</td>
      <td>{{ $a['consultant'] }}</td>
      <td>{{ $a['interval'] }}</td>
      <td class="r">{{ $a['duration'] }}</td>
      <td>{{ $a['billable'] ? 'Sim' : 'Não' }}</td>
    </tr>
    @endforeach
    <tr class="tot"><td colspan="3">Total</td><td class="r">{{ $hours['total'] }}</td><td></td></tr>
  </table>
  @if(count($hours['by']) > 1)
  <table class="hours" style="margin-top:8px">
    <tr><th>Consultor</th><th class="r">Total de horas</th></tr>
    @foreach($hours['by'] as $h)
    <tr><td>{{ $h['name'] }}</td><td class="r">{{ $h['h'] }}</td></tr>
    @endforeach
  </table>
  @endif
  @else
  <div class="desc muted">Nenhuma hora apontada neste chamado.</div>
  @endif
  @endif

  <div class="footer">ERPSERV · Minutor Help Desk — Relatório de Serviço {{ $ticket_number }} · gerado em {{ $generated_at }}</div>
</body>
</html>
