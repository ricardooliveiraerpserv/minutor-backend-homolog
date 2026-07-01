{{-- BASE — Processos de projeto (10 etapas, comum) --}}
<div class="slide">
  <div class="wm"><b>erp</b>serv</div>
  <div class="phead"><div class="ttl">Processos <em>de projeto</em></div></div>
  <div class="steps">
    @foreach ([
      'Proposta Comercial','Aceite do Projeto','Planejamento','Parametrização e/ou Desenvolvimento','Treinamento',
      'Homologação','Ajustes e Correções','Go-Live','Acompanhamento','Encerramento',
    ] as $i => $t)
      <div class="step"><span class="n">{{ $i+1 }}</span><div class="t">{{ $t }}</div></div>
    @endforeach
  </div>
  <div class="pfoot"><span>Proposta Comercial · {{ $p['tipo_label'] }}</span><span>{{ $p['codigo'] }}</span></div>
</div>
