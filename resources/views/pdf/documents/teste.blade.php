{{-- Template GENÉRICO de teste da Plataforma de Documentos (Fase 0.7). NÃO é proposta. --}}
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;color:#1e1e1e;padding:48px}
  .tag{display:inline-block;background:#EC6B2D;color:#fff;font-weight:700;padding:6px 14px;border-radius:4px;letter-spacing:.08em}
  h1{font-size:30px;margin:18px 0 4px}
  .meta{color:#666;font-size:13px;margin-bottom:24px}
  table{width:100%;border-collapse:collapse;margin-top:10px}
  th{background:#1B2027;color:#fff;text-align:left;padding:10px;font-size:12px;text-transform:uppercase}
  td{padding:10px;border-bottom:1px solid #e2e2e2}
  .total{background:#FCEDE3;font-weight:700;color:#D2541A}
  .foot{margin-top:30px;color:#999;font-size:11px;border-top:1px solid #e2e2e2;padding-top:10px}
</style></head><body>
  <span class="tag">PLATAFORMA DE DOCUMENTOS · TESTE</span>
  <h1>{{ $titulo ?? 'Documento de Teste' }}</h1>
  <div class="meta">Código <b>{{ $codigo ?? '—' }}</b> · Versão V{{ $versao ?? 1 }} · Gerado em {{ $gerado_em ?? now()->format('d/m/Y H:i') }}</div>
  <table>
    <tr><th>Item</th><th>Descrição</th><th style="text-align:right">Valor</th></tr>
    @foreach (($linhas ?? []) as $l)
      <tr><td>{{ $l['item'] }}</td><td>{{ $l['desc'] }}</td><td style="text-align:right">{{ $l['valor'] }}</td></tr>
    @endforeach
    <tr class="total"><td colspan="2">TOTAL</td><td style="text-align:right">{{ $total ?? 'R$ 0,00' }}</td></tr>
  </table>
  <div class="foot">Documento gerado pela Plataforma de Documentos do Minutor · motor: {{ $renderer ?? 'chromium' }}</div>
</body></html>
