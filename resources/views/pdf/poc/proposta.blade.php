{{--
  POC — Gerador de Propostas (Fase 0). Master MODULAR.
  $p      = dados dinâmicos (empresa, oportunidade, memória, proposta)
  $modulo = 'bh_fixo' | 'bh_mensal' | 'on_demand' | 'projeto_fechado'  (POC implementa bh_fixo)
  Estrutura: BASE (comum) + MÓDULO (específico por tipo). Nada é hardcoded de conteúdo dinâmico.
--}}
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">
<title>Proposta {{ $p['codigo'] }}</title>
@include('pdf.poc._styles')
</head><body>

  {{-- ===== BASE: capa ===== --}}
  @include('pdf.poc.base.capa')

  {{-- ===== BASE: dores + solução (institucional comum) ===== --}}
  @include('pdf.poc.base.dores')
  @include('pdf.poc.base.resolvemos')

  {{-- ===== MÓDULO: escopo/objetivo (varia por tipo) ===== --}}
  @include('pdf.poc.modulos.'.$modulo.'.escopo')

  {{-- ===== BASE: processos (comum) ===== --}}
  @include('pdf.poc.base.processos')

  {{-- ===== MÓDULO: investimento + prazo/pagamento (varia por tipo) ===== --}}
  @include('pdf.poc.modulos.'.$modulo.'.investimento')
  @include('pdf.poc.modulos.'.$modulo.'.prazo')

  {{-- ===== BASE: aceite (comum) + cláusula específica do módulo ===== --}}
  @include('pdf.poc.base.aceite')

  {{-- ===== BASE: obrigado/contato (comum) ===== --}}
  @include('pdf.poc.base.obrigado')

</body></html>
