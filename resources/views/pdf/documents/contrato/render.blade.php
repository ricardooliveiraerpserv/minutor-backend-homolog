<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  @page { size: A4; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; color: #1F2937; font-size: 11px; line-height: 1.6; }
  .page { padding: 48px 56px; }
  .kicker { font-size: 10px; letter-spacing: .22em; text-transform: uppercase; color: #0E7490; font-weight: 700; }
  h1 { font-size: 20px; font-weight: 800; margin: 6px 0 2px; color: #0F172A; }
  .meta { color: #6B7280; font-size: 11px; }
  .rule { height: 2px; background: #0E7490; width: 64px; margin: 14px 0 22px; border-radius: 2px; }
  .partes { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
  .partes td { vertical-align: top; width: 50%; padding: 14px 16px; border: 1px solid #E5E7EB; }
  .partes .lbl { font-size: 9px; letter-spacing: .12em; text-transform: uppercase; color: #0E7490; font-weight: 700; }
  .partes .nome { font-weight: 700; color: #0F172A; font-size: 12px; margin-top: 4px; }
  .partes .doc { color: #4B5563; }
  h2 { font-size: 12px; font-weight: 800; color: #0F172A; margin: 20px 0 4px; text-transform: uppercase; letter-spacing: .04em; }
  p { margin: 4px 0; text-align: justify; }
  .grid { width: 100%; border-collapse: collapse; margin: 8px 0 4px; }
  .grid td { border: 1px solid #E5E7EB; padding: 8px 12px; }
  .grid .k { background: #F8FAFC; font-weight: 700; color: #334155; width: 38%; }
  .escopo { white-space: pre-wrap; }
  .ass { width: 100%; margin-top: 56px; border-collapse: collapse; }
  .ass td { width: 50%; text-align: center; padding-top: 36px; }
  .ass .linha { border-top: 1px solid #475569; margin: 0 24px; padding-top: 6px; font-size: 10px; color: #334155; }
  .foot { margin-top: 28px; border-top: 1px solid #E5E7EB; padding-top: 10px; font-size: 9px; color: #9CA3AF; text-align: center; }
  .tag { display: inline-block; background: #ECFEFF; border: 1px solid #A5F0F7; color: #0E7490; font-weight: 700; padding: 2px 8px; border-radius: 6px; font-size: 11px; }
</style>
</head>
<body>
<div class="page">
  <div class="kicker">{{ $contratada['nome'] ?? 'ERPSERV Consultoria' }}</div>
  <h1>Contrato de Prestação de Serviços</h1>
  <div class="meta">Código <span class="tag">{{ $codigo ?? '—' }}</span> &middot; versão {{ $versao ?? 1 }} &middot; {{ $data ?? '' }}</div>
  <div class="rule"></div>

  <table class="partes">
    <tr>
      <td>
        <div class="lbl">Contratada</div>
        <div class="nome">{{ $contratada['nome'] ?? '—' }}</div>
        <div class="doc">CNPJ {{ $contratada['cnpj'] ?? '—' }}</div>
        @if(!empty($contratada['endereco']))<div class="doc">{{ $contratada['endereco'] }}</div>@endif
        @if(!empty($contratada['cep']))<div class="doc">CEP {{ $contratada['cep'] }}</div>@endif
      </td>
      <td>
        <div class="lbl">Contratante</div>
        <div class="nome">{{ $contratante['nome'] ?? '—' }}</div>
        <div class="doc">CNPJ/CPF {{ $contratante['cnpj'] ?? '—' }}</div>
      </td>
    </tr>
  </table>

  <h2>Cláusula 1ª — Do Objeto</h2>
  <p>O presente contrato tem por objeto a prestação, pela CONTRATADA à CONTRATANTE, dos serviços
  referentes a <strong>{{ $objeto ?? $codigo }}</strong>, na modalidade
  <strong>{{ $tipo_faturamento_label ?? '—' }}</strong>, conforme o escopo e as condições a seguir.</p>

  @if(!empty($escopo))
  <h2>Cláusula 2ª — Do Escopo</h2>
  <p class="escopo">{{ $escopo }}</p>
  @endif

  <h2>Cláusula 3ª — Do Valor e das Horas</h2>
  <table class="grid">
    @if(($horas ?? 0) > 0)<tr><td class="k">Horas contratadas</td><td>{{ $horas }} h</td></tr>@endif
    @if(($horas_coordenacao ?? 0) > 0)<tr><td class="k">Horas de coordenação</td><td>{{ $horas_coordenacao }} h</td></tr>@endif
    @if(!empty($valor_hora))<tr><td class="k">Valor por hora</td><td>{{ $valor_hora }}</td></tr>@endif
    <tr><td class="k">Valor total do contrato</td><td><strong>{{ $valor_projeto ?? '—' }}</strong></td></tr>
  </table>

  <h2>Cláusula 4ª — Do Prazo e do Pagamento</h2>
  <p>{{ $condicao_pagamento ?: 'As condições de pagamento serão observadas conforme acordado entre as partes.' }}@if(!empty($vigencia_meses)) A vigência prevista é de {{ $vigencia_meses }} meses.@endif</p>

  <h2>Cláusula 5ª — Das Disposições Gerais</h2>
  <p>Aplicam-se a este contrato as condições comerciais aprovadas na proposta de origem
  @if(!empty($proposta_ref))(<strong>{{ $proposta_ref }}</strong>)@endif, que dele passa a fazer parte integrante.
  Fica eleito o foro da comarca da sede da CONTRATADA para dirimir quaisquer questões oriundas deste instrumento.</p>

  <table class="ass">
    <tr>
      <td><div class="linha">{{ $contratada['nome'] ?? 'CONTRATADA' }}</div></td>
      <td><div class="linha">{{ $contratante['nome'] ?? 'CONTRATANTE' }}</div></td>
    </tr>
  </table>

  <div class="foot">Documento {{ $codigo ?? '' }} (v{{ $versao ?? 1 }}) gerado pelo Minutor &middot; {{ $data ?? '' }} &middot; sujeito a assinatura eletrônica.</div>
</div>
</body>
</html>
