{{-- MÓDULO BH FIXO — Investimento (tabela horas × valor/hora) --}}
<div class="slide">
  <div class="wm"><b>erp</b>serv</div>
  <div class="phead"><div class="ttl">Investimento</div></div>
  <table class="invest">
    <tr><th>Banco de Horas</th><th class="num">Horas</th><th class="num">Valor/hora</th><th class="num">Subtotal</th></tr>
    <tr>
      <td>Pacote fixo (utilização em até 01 ano)</td>
      <td class="num">{{ $p['inv']['horas'] }}</td>
      <td class="num">{{ $p['inv']['valor_hora_fmt'] }}</td>
      <td class="num">{{ $p['inv']['subtotal_fmt'] }}</td>
    </tr>
    <tr class="total"><td colspan="3">TOTAL</td><td class="num">{{ $p['inv']['total_fmt'] }}</td></tr>
  </table>

  <div style="position:absolute;left:660px;top:150px;width:556px">
    <div class="box" style="margin-bottom:14px">
      <b>Horas sob demanda:</b> caso a contratante ultrapasse as horas contratadas ou utilize demais
      serviços, será cobrado <b>{{ $p['inv']['excedente_fmt'] }}/hora</b> em horário comercial, com
      fechamento mensal e pagamento até o dia 10 do mês subsequente.
    </div>
    <div class="box">
      <b>Despesas — atendimento presencial:</b> São Paulo/SP {{ $p['despesa_sp_fmt'] }} por visita/consultor;
      fora de SP {{ $p['despesa_fora_fmt'] }} por visita/consultor (passagem aérea/km e hospedagem por conta
      da contratante).
    </div>
  </div>
  <div class="pfoot"><span>Banco de horas fixo · utilização em até 01 ano</span><span>{{ $p['codigo'] }}</span></div>
</div>
