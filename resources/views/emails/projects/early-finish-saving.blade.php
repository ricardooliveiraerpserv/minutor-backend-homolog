<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"></head>
<body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  @php $p = $d['project']; @endphp
  <div style="max-width:600px;margin:24px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
    <div style="background:#0f172a;color:#fff;padding:16px 24px;font-size:16px;font-weight:600;">🎯 Saving — Projeto finalizado antes do prazo</div>
    <div style="padding:24px;">
      <p style="font-size:14px;margin:0 0 16px;">
        O projeto <strong>{{ $p->name }}</strong> foi finalizado
        <strong style="color:#16a34a;">{{ $d['days_early'] }} dia(s) antes do prazo</strong>.
      </p>

      <div style="display:flex;gap:12px;margin-bottom:18px;">
        <div style="flex:1;border:1px solid #e5e7eb;border-radius:8px;padding:14px;text-align:center;">
          <div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Antecedência</div>
          <div style="font-size:22px;font-weight:700;color:#16a34a;">{{ $d['days_early'] }} dia(s)</div>
        </div>
        <div style="flex:1;border:1px solid #e5e7eb;border-radius:8px;padding:14px;text-align:center;">
          <div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Horas economizadas</div>
          <div style="font-size:22px;font-weight:700;color:#0ea5e9;">{{ number_format($d['hours_saved'], 1, ',', '.') }}h</div>
        </div>
      </div>

      <p style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin:0 0 6px;">Resumo do projeto</p>
      <table style="font-size:13px;color:#374151;width:100%;border-collapse:collapse;">
        <tr><td style="padding:4px 0;color:#6b7280;width:160px;">Cliente</td><td>{{ optional($p->customer)->name ?? '—' }}</td></tr>
        <tr><td style="padding:4px 0;color:#6b7280;">Código</td><td>{{ $p->code ?? '—' }}</td></tr>
        <tr><td style="padding:4px 0;color:#6b7280;">Coordenador(es)</td><td>{{ $d['coordenadores'] ?: '—' }}</td></tr>
        <tr><td style="padding:4px 0;color:#6b7280;">Prazo previsto</td><td>{{ \Carbon\Carbon::parse($d['prazo'])->format('d/m/Y') }}</td></tr>
        <tr><td style="padding:4px 0;color:#6b7280;">Encerramento</td><td><strong>{{ \Carbon\Carbon::parse($d['encerramento'])->format('d/m/Y') }}</strong></td></tr>
        <tr><td style="padding:4px 0;color:#6b7280;">Horas vendidas</td><td>{{ number_format((float) $p->sold_hours, 1, ',', '.') }}h</td></tr>
      </table>

      <p style="font-size:12px;color:#9ca3af;margin:18px 0 0;">Minutor · notificação automática de saving.</p>
    </div>
  </div>
</body>
</html>
