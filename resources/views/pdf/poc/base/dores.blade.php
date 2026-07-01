{{-- BASE — Problemas recorrentes (institucional, comum aos 4 modelos) --}}
<div class="slide">
  <div class="wm"><b>erp</b>serv</div>
  <div class="phead"><div class="ttl">Problemas <em>recorrentes</em></div></div>
  <div class="grid3">
    @foreach ([
      ['PROBLEMAS RECORRENTES','As ocorrências são tratadas de forma paliativa ao invés de definitiva.'],
      ['COMUNICAÇÃO INEFICIENTE','Falta resposta, retorno, posicionamento e feedback de status do atendimento.'],
      ['ATRASO NOS PROJETOS E ATENDIMENTOS','Não cumprimento dos prazos de entrega, atraso no atendimento e solução das prioridades.'],
      ['SISTEMA SUB UTILIZADO','O cliente não consegue explorar todos os recursos disponíveis no Sistema ERP.'],
      ['MÃO DE OBRA NÃO ESPECIALIZADA','Alocação de consultores com perfil inadequado para a demanda em questão.'],
      ['FALTA DE DOCUMENTAÇÃO E METODOLOGIA','Não há documentação objetiva e funcional, tampouco metodologia de trabalho eficaz.'],
    ] as $c)
      <div class="card"><h4>{{ $c[0] }}</h4><p>{{ $c[1] }}</p></div>
    @endforeach
  </div>
  <div class="pfoot"><span>Proposta Comercial · {{ $p['tipo_label'] }}</span><span>{{ $p['codigo'] }}</span></div>
</div>
