{{-- MÓDULO BH FIXO — Prazo e Pagamento --}}
<div class="slide">
  <div class="wm"><b>erp</b>serv</div>
  <div class="phead"><div class="ttl">Prazo e <em>pagamento</em></div></div>
  <table class="invest" style="width:620px">
    <tr><th>Parcelas</th><th>Valor</th><th>Vencimento</th></tr>
    @foreach (($p['parcelas'] ?? []) as $pc)
      <tr><td>{{ $pc['parc'] }}</td><td>{{ $pc['valor'] }}</td><td>{{ $pc['venc'] }}</td></tr>
    @endforeach
  </table>
  <div style="position:absolute;left:700px;top:150px;width:516px">
    <div class="box" style="margin-bottom:12px"><b>Início do atendimento:</b> em até 07 dias úteis após a assinatura da proposta.</div>
    <div class="box" style="margin-bottom:12px"><b>Duração do serviço:</b> prevista de <b>{{ $p['duracao_meses'] }} meses</b>.</div>
    <div class="box"><b>Pagamento das despesas:</b> despesas reembolsáveis cobradas via nota de débito no dia 10 do mês posterior à prestação dos serviços.</div>
  </div>
  <div class="pfoot"><span>Banco de horas fixo · utilização em até 01 ano</span><span>{{ $p['codigo'] }}</span></div>
</div>
