{{-- MÓDULO Banco de Horas Fixo — altera SÓ escopo / investimento / prazo / cláusula. Visual vem da base. --}}
@extends('pdf.documents.proposta.template-base')

@section('escopo')
<div class="slide"><div class="wm"><b>erp</b>serv</div><div class="phead"><div class="ttl">Tipo de escopo: <em>aberto</em></div></div>
  <div style="position:absolute;left:64px;top:130px;width:560px">
    <div class="kicker">Objetivo</div>
    <p style="font-size:16px;color:var(--ink-2);line-height:1.45;margin-top:8px">Aquisição de pacote de consultoria de <b>{{ $horas ?? 'XXX' }} horas</b> para serviços especializados em ERP Protheus, Infraestrutura e Power BI.</p>
    <div class="kicker" style="margin-top:22px">Escopo funcional</div>
    <p class="small muted" style="margin-top:6px;line-height:1.4">Abertura de canal de atendimento, nos moldes de banco de horas fixo, dentro do TOTVS Protheus, Fluig e Power BI, conforme alinhamento prévio com a contratante.</p>
  </div>
  <ul class="scope" style="position:absolute;left:660px;top:130px;width:556px">
    @foreach (($atividades ?? ['Diagnóstico de ambiente','Consultoria de processos','Sustentação','Manutenção','Desenvolvimentos','Gerenciamento de Projetos']) as $a)<li>{{ $a }}</li>@endforeach
  </ul>
  <div class="pfoot"><span>Proposta Comercial · {{ $tipo_label ?? '' }}</span><span>{{ $codigo ?? '' }} · pág <span class="pg"></span></span></div>
</div>
@endsection

@section('investimento')
<div class="slide"><div class="wm"><b>erp</b>serv</div><div class="phead"><div class="ttl">Investimento</div></div>
  <table class="ds" style="position:absolute;left:64px;top:150px;width:600px">
    <tr><th>Banco de Horas</th><th class="num">Horas</th><th class="num">Valor/hora</th><th class="num">Subtotal</th></tr>
    <tr><td>Pacote fixo (utilização em até 01 ano)</td><td class="num">{{ $horas ?? '' }}</td><td class="num">{{ $valor_hora ?? '' }}</td><td class="num">{{ $faturamento ?? '' }}</td></tr>
    <tr class="total"><td colspan="3">TOTAL</td><td class="num">{{ $faturamento ?? '' }}</td></tr>
  </table>
  <div style="position:absolute;left:700px;top:150px;width:516px">
    <div class="box" style="margin-bottom:14px"><b>Horas sob demanda:</b> excedente ou demais serviços cobrados a <b>{{ $excedente ?? 'R$ 190,00' }}/hora</b> em horário comercial, fechamento mensal, pagamento até dia 10 do mês subsequente.</div>
    <div class="box"><b>Despesas — atendimento presencial:</b> São Paulo/SP {{ $despesa_sp ?? 'R$ 170,00' }} por visita/consultor; fora de SP {{ $despesa_fora ?? 'R$ 250,00' }} (passagem aérea/km e hospedagem por conta da contratante).</div>
  </div>
  <div class="pfoot"><span>Banco de horas fixo · utilização em até 01 ano</span><span>{{ $codigo ?? '' }} · pág <span class="pg"></span></span></div>
</div>
@endsection

@section('prazo_pagamento')
<div class="slide"><div class="wm"><b>erp</b>serv</div><div class="phead"><div class="ttl">Prazo e <em>pagamento</em></div></div>
  <table class="ds" style="position:absolute;left:64px;top:150px;width:620px">
    <tr><th>Parcelas</th><th>Valor</th><th>Vencimento</th></tr>
    @foreach (($parcelas ?? [['parc'=>'2x','valor'=>'50% em cada parcela','venc'=>'10 / 40 dias após assinatura']]) as $pc)
      <tr><td>{{ $pc['parc'] }}</td><td>{{ $pc['valor'] }}</td><td>{{ $pc['venc'] }}</td></tr>
    @endforeach
  </table>
  <div style="position:absolute;left:700px;top:150px;width:516px">
    <div class="box" style="margin-bottom:12px"><b>Início:</b> em até 07 dias úteis após a assinatura.</div>
    <div class="box" style="margin-bottom:12px"><b>Duração:</b> prevista de <b>{{ $duracao_meses ?? 'X' }} meses</b>.</div>
    <div class="box"><b>Despesas:</b> reembolsáveis via nota de débito no dia 10 do mês posterior à prestação.</div>
  </div>
  <div class="pfoot"><span>Banco de horas fixo · utilização em até 01 ano</span><span>{{ $codigo ?? '' }} · pág <span class="pg"></span></span></div>
</div>
@endsection

@section('clausula')
<div class="box" style="position:absolute;left:64px;bottom:150px;right:64px"><b>Banco de Horas Fixo:</b> as horas são expectativas conforme alinhamento entre as partes, podendo ser flexibilizadas para menor ou maior. A capacidade mensal é definida pela divisão do Banco de Horas total pelo número de meses do contrato.</div>
@endsection
