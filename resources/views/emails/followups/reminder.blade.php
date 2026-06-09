<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"></head>
<body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <div style="max-width:560px;margin:24px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
    <div style="background:#0f172a;color:#fff;padding:16px 24px;font-size:16px;font-weight:600;">Minutor · Follow Up</div>
    <div style="padding:24px;">
      @php
        $msg = match ($kind) {
          'd5' => 'Este Follow Up vence em <strong>5 dias</strong>.',
          'd3' => 'Este Follow Up vence em <strong>3 dias</strong>.',
          'd1' => 'Este Follow Up vence <strong>amanhã</strong>.',
          'due' => 'Este Follow Up vence <strong>hoje</strong>.',
          'overdue' => 'Este Follow Up está <strong>atrasado há ' . ($f->days_overdue ?? 0) . ' dia(s)</strong>.',
          default => 'Lembrete de Follow Up.',
        };
      @endphp
      <p style="font-size:14px;margin:0 0 16px;">{!! $msg !!}</p>
      <div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
        <div style="font-size:15px;font-weight:600;margin-bottom:8px;">{{ $f->title }}</div>
        @if($f->description)<div style="font-size:13px;color:#4b5563;margin-bottom:12px;">{{ \Illuminate\Support\Str::limit($f->description, 240) }}</div>@endif
        <table style="font-size:13px;color:#374151;width:100%;border-collapse:collapse;">
          <tr><td style="padding:3px 0;color:#6b7280;width:120px;">Prazo</td><td>{{ optional($f->due_date)->format('d/m/Y') ?? '—' }}</td></tr>
          <tr><td style="padding:3px 0;color:#6b7280;">Prioridade</td><td style="text-transform:capitalize;">{{ $f->priority }}</td></tr>
          <tr><td style="padding:3px 0;color:#6b7280;">Projeto</td><td>{{ optional($f->project)->name ?? '—' }}</td></tr>
          <tr><td style="padding:3px 0;color:#6b7280;">Empresa</td><td>{{ optional($f->customer)->name ?? '—' }}</td></tr>
          <tr><td style="padding:3px 0;color:#6b7280;">Responsável</td><td>{{ optional($f->responsible)->name ?? '—' }}</td></tr>
        </table>
      </div>
      <p style="font-size:12px;color:#9ca3af;margin:16px 0 0;">Acompanhe em Minutor → Follow Up.</p>
    </div>
  </div>
</body>
</html>
