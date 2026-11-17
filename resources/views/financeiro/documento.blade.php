@php
    $t = $nota->tomador ?? [];
    $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
    $doc = fn ($d) => strlen(preg_replace('/\D/', '', (string) $d)) === 14
        ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', (string) $d))
        : (string) $d;
    $titulo = $nota->tipo === 'nota_debito' ? 'NOTA DE DÉBITO' : ($nota->emissor === 'simulado' ? 'NFS-e SIMULADA' : 'NFS-e');
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $titulo }} {{ $nota->numero }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111; margin: 24px; }
  h1 { font-size: 16px; margin: 0; }
  .muted { color: #555; }
  .box { border: 1px solid #999; padding: 8px 10px; margin-top: 10px; }
  .row { width: 100%; }
  .row td { vertical-align: top; padding: 2px 4px; }
  table.v { width: 100%; border-collapse: collapse; margin-top: 6px; }
  table.v td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
  table.v td.r { text-align: right; }
  .tot td { font-weight: bold; border-top: 2px solid #333; }
  .aviso { background: #fde68a; padding: 6px 8px; font-weight: bold; text-align: center; margin-bottom: 8px; }
</style>
</head>
<body>
@if ($nota->emissor === 'simulado')
  <div class="aviso">DOCUMENTO SIMULADO — SEM VALOR FISCAL (ambiente de testes)</div>
@endif
<table class="row"><tr>
  <td>
    <h1>{{ $empresa->razao_social ?? $nota->company?->name }}</h1>
    <div class="muted">CNPJ {{ $doc($nota->company?->cnpj ?? $empresa->certificado_cnpj) }} · IM {{ $empresa->inscricao_municipal }}</div>
    <div class="muted">{{ $empresa->logradouro }}, {{ $empresa->numero }} — {{ $empresa->bairro }} — {{ $empresa->cidade }}/{{ $empresa->uf }}</div>
  </td>
  <td style="text-align:right">
    <h1>{{ $titulo }}</h1>
    <div>Nº <strong>{{ $nota->numero }}</strong>@if ($nota->serie) · Série {{ $nota->serie }}@endif</div>
    <div class="muted">Emissão {{ optional($nota->emitida_em ?? $nota->data_emissao)->format('d/m/Y') }} · Competência {{ substr($nota->competencia, 5, 2) }}/{{ substr($nota->competencia, 0, 4) }}</div>
    @if ($nota->chave_acesso)<div class="muted">Chave {{ $nota->chave_acesso }}</div>@endif
    @if ($nota->codigo_verificacao)<div class="muted">Cód. verificação {{ $nota->codigo_verificacao }}</div>@endif
  </td>
</tr></table>

<div class="box">
  <strong>{{ $nota->tipo === 'nota_debito' ? 'Devedor' : 'Tomador do serviço' }}</strong><br>
  {{ $t['company_name'] ?? $t['name'] ?? '—' }} — CNPJ/CPF {{ $doc($t['cgc'] ?? '') }}@if (!empty($t['inscricao_municipal'])) · IM {{ $t['inscricao_municipal'] }}@endif<br>
  <span class="muted">{{ $t['logradouro'] ?? '' }}, {{ $t['numero'] ?? '' }} {{ $t['complemento'] ?? '' }} — {{ $t['bairro'] ?? '' }} — {{ $t['cidade'] ?? '' }}/{{ $t['uf'] ?? '' }} — CEP {{ $t['cep'] ?? '' }}</span>
</div>

<div class="box">
  <strong>{{ $nota->tipo === 'nota_debito' ? 'Descrição (reembolso de despesas, sem incidência de tributos)' : 'Discriminação do serviço' }}</strong>
  <p style="white-space: pre-line; margin: 6px 0 0">{{ $nota->discriminacao }}</p>
  @if ($nota->tipo === 'nota_debito' && !empty($despesas))
    <table class="v">
      @foreach ($despesas as $d)
        <tr><td>{{ isset($d['data']) ? \Carbon\Carbon::parse($d['data'])->format('d/m/Y') : '' }}</td><td>{{ $d['descricao'] ?? '' }}</td><td class="r">R$ {{ $fmt($d['valor'] ?? 0) }}</td></tr>
      @endforeach
    </table>
  @endif
</div>

<div class="box">
  <table class="v">
    <tr><td>Valor {{ $nota->tipo === 'nota_debito' ? 'do débito' : 'do serviço' }}</td><td class="r">R$ {{ $fmt($nota->valor_servico) }}</td></tr>
    @if ((float) $nota->desconto > 0)<tr><td>(-) Desconto</td><td class="r">R$ {{ $fmt($nota->desconto) }}</td></tr>@endif
    @if ($nota->tipo === 'nfse')
      @foreach (['ret_irrf' => 'IRRF retido', 'ret_csll' => 'CSLL retida', 'ret_pis' => 'PIS retido', 'ret_cofins' => 'COFINS retida', 'ret_iss' => 'ISS retido'] as $k => $rot)
        @if ((float) ($parcela->{$k} ?? 0) > 0)<tr><td>(-) {{ $rot }}</td><td class="r">R$ {{ $fmt($parcela->{$k}) }}</td></tr>@endif
      @endforeach
      <tr><td class="muted">ISS (informativo)</td><td class="r muted">R$ {{ $fmt($nota->valor_iss) }}</td></tr>
    @endif
    <tr class="tot"><td>Valor líquido a pagar</td><td class="r">R$ {{ $fmt($nota->valor_liquido) }}</td></tr>
  </table>
</div>
</body>
</html>
