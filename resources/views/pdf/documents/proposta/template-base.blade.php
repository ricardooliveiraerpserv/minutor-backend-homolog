{{--
  TEMPLATE-BASE (Fase 1.3) — identidade visual CENTRALIZADA da proposta ERPSERV.
  Comum (1x): capa, problemas, solução, processos, aceite, contatos, paleta, tipografia, componentes.
  Os módulos (@extends desta base) preenchem só: @yield escopo / investimento / prazo_pagamento / clausula.
  Carimbos de status + placeholders de assinatura/QR já previstos.
--}}
@php
  $officeUri = \App\Documents\DocumentAssets::dataUri('office.jpg');
  // Logo OFICIAL: usa o arquivo institucional quando presente (resources/documents-assets/logo-erpserv.svg|png).
  $logoUri = \App\Documents\DocumentAssets::dataUri('logo-erpserv.svg') ?: \App\Documents\DocumentAssets::dataUri('logo-erpserv.png');
@endphp
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Proposta {{ $codigo ?? '' }}</title>
<style>
  /* PALETA OFICIAL ERPSERV (extraída do material institucional): roxo + índigo + teal. */
  :root{
    --brand:#530082; --brand-d:#400064; --brand-dark:#442B7E; --teal:#10AAA5; --teal-d:#0C8A86;
    /* aliases legados → cores oficiais (toda a base/módulos herdam sem retrabalho) */
    --orange:#530082; --orange-d:#400064; --dark:#442B7E;
    --ink:#1E1E1E; --ink-2:#3A3A3A; --gray:#7A7A7A; --line:#E2E2E2; --paper:#fff;
  }
  *{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  html,body{font-family:'Calibri','Segoe UI',Arial,Helvetica,sans-serif;color:var(--ink);}
  @page{size:1280px 720px;margin:0;}
  body{counter-reset:slide;}
  .slide{position:relative;width:1280px;height:720px;overflow:hidden;background:var(--paper);page-break-after:always;counter-increment:slide;}
  .slide:last-child{page-break-after:auto;}
  .kicker{color:var(--orange);font-weight:700;letter-spacing:.16em;text-transform:uppercase;font-size:15px;}
  .muted{color:var(--gray);} .small{font-size:13px;} .tiny{font-size:11px;}
  .phead{position:absolute;top:54px;left:64px;} .phead .ttl{font-size:30px;font-weight:800;} .phead .ttl em{color:var(--teal-d);font-style:normal;}
  .wm{position:absolute;right:60px;top:46px;font-weight:800;font-size:18px;} .wm b{color:var(--orange);}
  /* rodapé + numeração automática */
  .pfoot{position:absolute;bottom:26px;left:64px;right:64px;display:flex;justify-content:space-between;align-items:center;color:var(--gray);font-size:11px;border-top:1px solid var(--line);padding-top:10px;}
  .pfoot .pg::after{content:counter(slide);}
  .erp-logo{font-weight:800;font-size:30px;letter-spacing:-.5px;color:#fff;} .erp-logo b{color:var(--orange);}
  .erp-logo span{display:block;font-size:11px;letter-spacing:.42em;color:#cfd3d8;font-weight:600;margin-top:-4px;}
  /* CAPA */
  .cover{background:var(--dark);} .cover .left{position:absolute;inset:0 46% 0 0;padding:70px 60px;display:flex;flex-direction:column;justify-content:space-between;}
  .cover .right{position:absolute;inset:0 0 0 54%;background:#2a1a4d;} .cover .right .photo{position:absolute;inset:0;background:#000 center/cover no-repeat;filter:grayscale(1) contrast(1.05);opacity:.9;}
  .cover .right:after{content:'';position:absolute;left:-40px;top:0;bottom:0;width:80px;background:var(--dark);transform:skewX(-7deg);}
  .cover .badge{display:inline-block;background:var(--orange);color:#fff;font-weight:800;letter-spacing:.12em;padding:8px 16px;border-radius:3px;font-size:14px;}
  .cover .fields{display:grid;grid-template-columns:auto 1fr;gap:9px 18px;font-size:16px;color:#dfe3e8;margin-top:8px;}
  .cover .kicker{color:var(--teal);}
  .cover .fields .k{color:var(--teal);font-weight:700;text-transform:uppercase;font-size:13px;letter-spacing:.06em;align-self:center;}
  .cover .logobox{position:absolute;right:48px;bottom:48px;width:190px;height:92px;border:2px dashed rgba(255,255,255,.35);border-radius:8px;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.6);font-size:12px;}
  /* componentes reutilizáveis */
  .grid3{position:absolute;left:64px;right:64px;top:120px;display:grid;grid-template-columns:repeat(3,1fr);gap:18px;}
  .card{background:#fff;border:1px solid var(--line);border-top:4px solid var(--orange);border-radius:8px;padding:18px;}
  .card h4{font-size:15px;font-weight:800;margin-bottom:6px;text-transform:uppercase;} .card p{font-size:12.5px;color:var(--ink-2);line-height:1.35;}
  .sol .card{border-top-color:var(--dark);}
  table.ds{border-collapse:collapse;font-size:15px;} table.ds th{background:var(--dark);color:#fff;text-align:left;padding:11px 14px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;}
  table.ds td{padding:11px 14px;border-bottom:1px solid var(--line);} table.ds tr.total td{background:#EFE6F6;font-weight:800;color:var(--brand-d);border:none;} .num{text-align:right;}
  .box{background:#F7F7F7;border-left:4px solid var(--orange);border-radius:6px;padding:14px 16px;font-size:12.5px;color:var(--ink-2);line-height:1.4;}
  ul.scope{list-style:none;} ul.scope li{font-size:14px;color:var(--ink-2);padding:7px 0 7px 24px;position:relative;border-bottom:1px dashed var(--line);}
  ul.scope li:before{content:'';position:absolute;left:0;top:14px;width:9px;height:9px;background:var(--orange);border-radius:2px;}
  .steps{position:absolute;left:64px;right:64px;top:170px;display:grid;grid-template-columns:repeat(5,1fr);gap:14px;}
  .step{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px;text-align:center;}
  .step .n{display:inline-flex;width:34px;height:34px;border-radius:50%;background:var(--orange);color:#fff;font-weight:800;align-items:center;justify-content:center;margin-bottom:8px;} .step .t{font-size:12px;font-weight:700;text-transform:uppercase;}
  .sign{position:absolute;top:150px;left:64px;display:grid;gap:10px;width:430px;} .sign .ln{border-bottom:1.5px solid var(--ink);height:34px;} .sign .lbl{font-size:12px;color:var(--gray);text-transform:uppercase;}
  .contratada{position:absolute;right:64px;top:150px;width:430px;background:#F7F7F7;border-radius:8px;padding:18px;font-size:12.5px;color:var(--ink-2);line-height:1.5;}
  .terms{position:absolute;left:64px;right:64px;bottom:54px;font-size:10.5px;color:var(--gray);line-height:1.4;}
  .thanks{background:var(--dark);} .thanks .photo{position:absolute;inset:0;background:#000 center/cover;filter:grayscale(1);opacity:.35;}
  .thanks .c{position:absolute;left:64px;bottom:80px;color:#fff;} .thanks .c .big{font-size:60px;font-weight:800;} .thanks .c .big b{color:var(--orange);} .thanks .c .contact{margin-top:14px;font-size:15px;color:#dfe3e8;line-height:1.7;}
  /* FUTURO (previsto): QR + assinatura digital/eletrônica */
  .sign-future{position:absolute;right:64px;bottom:120px;width:430px;display:flex;gap:14px;align-items:center;}
  .qr{width:96px;height:96px;border:1px dashed var(--gray);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--gray);font-size:10px;text-align:center;}
  .sign-future .lbl{font-size:11px;color:var(--gray);line-height:1.4;}
  /* CARIMBOS de status */
  .stamp{position:absolute;top:120px;right:70px;transform:rotate(-12deg);border:5px solid;border-radius:10px;padding:6px 22px;font-weight:900;font-size:34px;letter-spacing:.06em;text-transform:uppercase;opacity:.85;z-index:20;}
  .stamp.rev{color:#B58100;border-color:#B58100;} .stamp.canc{color:#C0392B;border-color:#C0392B;} .stamp.conv{color:#1E8449;border-color:#1E8449;}
</style></head><body>

  {{-- Carimbo de status (previsto): Revisão / Cancelada / Convertida --}}
  @php $st = $status ?? null; @endphp
  @if($st === 'reativada' || ($versao ?? 1) > 1)
    @php($stampOnce = true)
  @endif

  {{-- ===== CAPA (base) ===== --}}
  <div class="slide cover">
    @if(($versao ?? 1) > 1)<div class="stamp rev">Revisão {{ $versao }}</div>@endif
    @if($st === 'cancelada')<div class="stamp canc">Cancelada</div>@endif
    @if($st === 'convertida')<div class="stamp conv">Convertida</div>@endif
    <div class="left">
      @if($logoUri)<img src="{{ $logoUri }}" alt="ERPSERV" style="height:50px;width:auto">@else<div class="erp-logo"><b>erp</b>serv <span>consultoria</span></div>@endif
      <div>
        <div class="kicker">Proposta Comercial</div>
        <div class="badge" style="margin:14px 0 22px">{{ $tipo_label ?? 'PROPOSTA' }}</div>
        <div class="fields">
          <div class="k">ID</div><div>{{ $codigo ?? '—' }}</div>
          <div class="k">Versão</div><div>{{ str_pad((string)($versao ?? 1), 2, '0', STR_PAD_LEFT) }}</div>
          <div class="k">Cliente</div><div>{{ $cliente ?? '—' }}</div>
          <div class="k">CNPJ</div><div>{{ $cnpj ?? '—' }}</div>
          <div class="k">Executivo</div><div>{{ $executivo ?? '—' }}</div>
          <div class="k">Data</div><div>{{ $gerado_em ?? '' }}</div>
        </div>
      </div>
      <div class="tiny" style="color:#8b929b">ERPSERV Consultoria de Sistemas Ltda · comercial@erpserv.com.br</div>
    </div>
    <div class="right"><div class="photo" style="background-image:url('{{ $officeUri }}')"></div><div class="logobox">LOGO DO CLIENTE</div></div>
  </div>

  {{-- ===== PROBLEMAS (base) ===== --}}
  <div class="slide"><div class="wm"><b>erp</b>serv</div><div class="phead"><div class="ttl">Problemas <em>recorrentes</em></div></div>
    <div class="grid3">
      @foreach ([['PROBLEMAS RECORRENTES','As ocorrências são tratadas de forma paliativa ao invés de definitiva.'],['COMUNICAÇÃO INEFICIENTE','Falta resposta, retorno, posicionamento e feedback de status do atendimento.'],['ATRASO NOS PROJETOS E ATENDIMENTOS','Não cumprimento dos prazos, atraso no atendimento e solução das prioridades.'],['SISTEMA SUB UTILIZADO','O cliente não consegue explorar todos os recursos disponíveis no ERP.'],['MÃO DE OBRA NÃO ESPECIALIZADA','Alocação de consultores com perfil inadequado para a demanda.'],['FALTA DE DOCUMENTAÇÃO E METODOLOGIA','Não há documentação objetiva e funcional, tampouco metodologia eficaz.']] as $c)
        <div class="card"><h4>{{ $c[0] }}</h4><p>{{ $c[1] }}</p></div>
      @endforeach
    </div><div class="pfoot"><span>Proposta Comercial · {{ $tipo_label ?? '' }}</span><span>{{ $codigo ?? '' }} · pág <span class="pg"></span></span></div>
  </div>

  {{-- ===== COMO RESOLVEMOS (base) ===== --}}
  <div class="slide sol"><div class="wm"><b>erp</b>serv</div><div class="phead"><div class="ttl">Como <em>resolvemos!</em></div></div>
    <div class="grid3">
      @foreach ([['PROBLEMAS RECORRENTES','Foco total na causa raiz, com Dashboard de acompanhamento em tempo real.'],['COMUNICAÇÃO INEFICIENTE','Canais eficazes de atendimento, estrutura 100% digital.'],['ATRASO NOS PROJETOS E ATENDIMENTOS','Sprints de entregas semanais com status para o cliente acompanhar.'],['SISTEMA SUB UTILIZADO','Buscamos as melhores soluções focadas na operação do cliente.'],['MÃO DE OBRA NÃO ESPECIALIZADA','Recursos certificados e academia interna para evolução do time.'],['FALTA DE DOCUMENTAÇÃO E METODOLOGIA','Documentação própria e sem burocracia, para o mercado de ERP.']] as $c)
        <div class="card"><h4>{{ $c[0] }}</h4><p>{{ $c[1] }}</p></div>
      @endforeach
    </div><div class="pfoot"><span>Proposta Comercial · {{ $tipo_label ?? '' }}</span><span>{{ $codigo ?? '' }} · pág <span class="pg"></span></span></div>
  </div>

  {{-- ===== ESCOPO (MÓDULO) ===== --}}
  @yield('escopo')

  {{-- ===== PROCESSOS (base) ===== --}}
  <div class="slide"><div class="wm"><b>erp</b>serv</div><div class="phead"><div class="ttl">Processos <em>de projeto</em></div></div>
    <div class="steps">
      @foreach (['Proposta Comercial','Aceite do Projeto','Planejamento','Parametrização/Desenvolvimento','Treinamento','Homologação','Ajustes e Correções','Go-Live','Acompanhamento','Encerramento'] as $i => $t)
        <div class="step"><span class="n">{{ $i+1 }}</span><div class="t">{{ $t }}</div></div>
      @endforeach
    </div><div class="pfoot"><span>Proposta Comercial · {{ $tipo_label ?? '' }}</span><span>{{ $codigo ?? '' }} · pág <span class="pg"></span></span></div>
  </div>

  {{-- ===== INVESTIMENTO (MÓDULO) ===== --}}
  @yield('investimento')

  {{-- ===== PRAZO E PAGAMENTO (MÓDULO) ===== --}}
  @yield('prazo_pagamento')

  {{-- ===== ACEITE (base) + cláusula (MÓDULO) ===== --}}
  <div class="slide"><div class="wm"><b>erp</b>serv</div><div class="phead"><div class="ttl">Aceite <em>da proposta</em></div></div>
    <div class="sign">
      <div class="lbl">Aceite da Contratante — Representante Legal</div>
      <div class="ln"></div><div class="lbl">Nome completo</div>
      <div class="ln"></div><div class="lbl">Data</div>
      <div class="ln"></div><div class="lbl">Assinatura</div>
      <div class="lbl" style="margin-top:8px">Valor: <b style="color:var(--orange-d)">{{ $faturamento ?? '' }}</b></div>
    </div>
    <div class="contratada"><b>Dados da contratada</b><br>ERPSERV CONSULTORIA DE SISTEMAS LTDA<br>Av. Francisco Matarazzo, 1752 – Conj. 2607 – Água Branca – SP/Brasil<br>CNPJ: 23.870.826/0001-07 · CEP: 05001-200<br><span class="tiny">Contrato registrado no 8º Oficial de RTD de São Paulo sob nº 1.546.416.</span></div>
    {{-- previsto p/ futuro: assinatura eletrônica + QR de validação --}}
    <div class="sign-future"><div class="qr">QR<br>assinatura</div><div class="lbl">Assinatura digital / aprovação eletrônica<br>(Assinei · DocuSign · Clicksign) — em breve</div></div>
    @yield('clausula')
    <div class="terms">Termos de aceitação: o contrato é composto pelo documento descrito, disponível em erpserv.com.br, cujos termos o CLIENTE declara conhecimento e concordância. Foro Central de São Paulo – Capital.</div>
    <div class="pfoot" style="bottom:14px"><span>Proposta Comercial · {{ $tipo_label ?? '' }}</span><span>{{ $codigo ?? '' }} · pág <span class="pg"></span></span></div>
  </div>

  {{-- ===== OBRIGADO (base) ===== --}}
  <div class="slide thanks"><div class="photo" style="background-image:url('{{ $officeUri }}')"></div>
    <div class="c">@if($logoUri)<img src="{{ $logoUri }}" alt="ERPSERV" style="height:50px;width:auto;margin-bottom:18px;display:block">@else<div class="erp-logo" style="margin-bottom:18px"><b>erp</b>serv <span>consultoria</span></div>@endif<div class="big">Obrigado<b>!</b></div>

      <div class="contact">+55 (11) 3230.9647<br>comercial@erpserv.com.br · erpserv.com.br<br>Av. Francisco Matarazzo, 1752 – Água Branca – São Paulo/SP</div></div>
  </div>

</body></html>
