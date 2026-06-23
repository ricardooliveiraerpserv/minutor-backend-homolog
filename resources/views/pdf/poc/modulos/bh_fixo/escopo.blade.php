{{-- MÓDULO BH FIXO — Escopo / Objetivo --}}
<div class="slide">
  <div class="wm"><b>erp</b>serv</div>
  <div class="phead"><div class="ttl">Tipo de escopo: <em>aberto</em></div></div>
  <div style="position:absolute;left:64px;top:130px;width:560px">
    <div class="kicker">Objetivo</div>
    <p style="font-size:16px;color:var(--ink-2);line-height:1.45;margin-top:8px">
      Aquisição de pacote de consultoria de <b>{{ $p['objetivo_horas'] }} horas</b> para serviços
      especializados em ERP Protheus, Infraestrutura e Power BI.
    </p>
    <div class="kicker" style="margin-top:22px">Escopo funcional</div>
    <p class="small muted" style="margin-top:6px;line-height:1.4">
      Abertura de canal de atendimento, nos moldes de banco de horas fixo, dentro do TOTVS Protheus,
      Fluig e Power BI, conforme alinhamento prévio com a contratante.
    </p>
  </div>
  <ul class="scope" style="left:660px;top:130px;width:556px">
    @foreach (($p['atividades'] ?? []) as $a)<li>{{ $a }}</li>@endforeach
  </ul>
  <div class="pfoot"><span>Proposta Comercial · {{ $p['tipo_label'] }}</span><span>{{ $p['codigo'] }}</span></div>
</div>
