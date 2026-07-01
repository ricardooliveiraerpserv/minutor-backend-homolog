{{-- BASE — Aceite (comum) + cláusula específica do módulo --}}
<div class="slide">
  <div class="wm"><b>erp</b>serv</div>
  <div class="phead"><div class="ttl">Aceite <em>da proposta</em></div></div>

  <div class="sign" style="left:64px">
    <div class="lbl">Aceite da Contratante — Representante Legal</div>
    <div class="ln"></div><div class="lbl">Nome completo</div>
    <div class="ln"></div><div class="lbl">Data</div>
    <div class="ln"></div><div class="lbl">Assinatura</div>
    <div class="lbl" style="margin-top:10px">Valor: <b style="color:var(--orange-d)">{{ $p['valor_total_fmt'] }}</b></div>
  </div>

  <div class="contratada">
    <b>Dados da contratada</b><br>
    ERPSERV CONSULTORIA DE SISTEMAS LTDA<br>
    Av. Francisco Matarazzo, 1752 – Conj. 2607 – Água Branca – SP/Brasil<br>
    CNPJ: 23.870.826/0001-07 · CEP: 05001-200<br>
    <span class="tiny">Contrato registrado no 8º Oficial de Registro de Títulos e Documentos de São Paulo sob nº 1.546.416.</span>
  </div>

  {{-- cláusula específica do tipo de proposta --}}
  @include('pdf.poc.modulos.'.$modulo.'.clausula')

  <div class="terms">
    Termos de aceitação: o contrato ora firmado é composto pelo documento descrito, disponível em
    erpserv.com.br, cujos termos o CLIENTE declara conhecimento e concordância. As partes elegem o Foro
    Central de São Paulo – Capital para solução de quaisquer pendências oriundas do presente contrato.
  </div>
  <div class="pfoot" style="bottom:14px"><span>Proposta Comercial · {{ $p['tipo_label'] }}</span><span>{{ $p['codigo'] }}</span></div>
</div>
