{{-- BASE — Como resolvemos (institucional, comum) --}}
<div class="slide sol">
  <div class="wm"><b>erp</b>serv</div>
  <div class="phead"><div class="ttl">Como <em>resolvemos!</em></div></div>
  <div class="grid3">
    @foreach ([
      ['PROBLEMAS RECORRENTES','Foco total na causa raiz, além de contar com Dashboard de acompanhamento em tempo real.'],
      ['COMUNICAÇÃO INEFICIENTE','Disponibilizamos canais eficazes de atendimento, com estrutura 100% digital.'],
      ['ATRASO NOS PROJETOS E ATENDIMENTOS','Sprints de entregas semanais com status para o cliente acompanhar o andamento do projeto.'],
      ['SISTEMA SUB UTILIZADO','Alinhado ao nosso propósito, buscamos as melhores soluções focadas na operação do cliente.'],
      ['MÃO DE OBRA NÃO ESPECIALIZADA','Recursos certificados e academia interna para constante evolução do time.'],
      ['FALTA DE DOCUMENTAÇÃO E METODOLOGIA','Documentação própria e sem burocracia, desenvolvida para o mercado de ERP.'],
    ] as $c)
      <div class="card"><h4>{{ $c[0] }}</h4><p>{{ $c[1] }}</p></div>
    @endforeach
  </div>
  <div class="pfoot"><span>Proposta Comercial · {{ $p['tipo_label'] }}</span><span>{{ $p['codigo'] }}</span></div>
</div>
