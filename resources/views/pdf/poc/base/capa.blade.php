{{-- BASE — Capa (layout comum; campos dinâmicos) --}}
<div class="slide cover">
  <div class="left">
    <div>
      <div class="erp-logo"><b>erp</b>serv <span>consultoria</span></div>
    </div>
    <div>
      <div class="kicker" style="color:var(--orange)">Proposta Comercial</div>
      <div class="badge" style="margin:14px 0 22px">{{ $p['tipo_label'] }}</div>
      <div class="fields">
        <div class="k">ID</div><div>{{ $p['codigo'] }}</div>
        <div class="k">Versão</div><div>{{ $p['versao'] }}</div>
        <div class="k">Cliente</div><div>{{ $p['cliente'] }}</div>
        <div class="k">CNPJ</div><div>{{ $p['cnpj'] }}</div>
        <div class="k">Executivo</div><div>{{ $p['executivo'] }}</div>
        <div class="k">Data</div><div>{{ $p['data'] }}</div>
      </div>
    </div>
    <div class="tiny" style="color:#8b929b">ERPSERV Consultoria de Sistemas Ltda · comercial@erpserv.com.br</div>
  </div>
  <div class="right">
    <div class="photo" style="background-image:url('{{ $officeImg }}')"></div>
    <div class="logobox">LOGO DO CLIENTE</div>
  </div>
</div>
