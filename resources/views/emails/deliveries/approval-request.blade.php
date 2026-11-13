<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><title>Aprovação solicitada — {{ $d->title }}</title></head>
<body style="margin:0;padding:0;background:#000000;font-family:'Segoe UI',-apple-system,Helvetica,Arial,sans-serif;">
<div style="max-width:640px;margin:0 auto;padding:24px 16px;">

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
    style="background:#000000;border-radius:16px;overflow:hidden;border:1px solid #3F3F46;">

    @include('emails.cards._partial-header')

    <tr>
      <td style="padding:32px 40px 4px;background:#000000;">
        <div style="font-size:11px;letter-spacing:.22em;color:#FBBF24;font-weight:800;text-transform:uppercase;">Aprovação solicitada</div>
        <h1 style="margin:8px 0 4px;color:#FFFFFF;font-size:23px;line-height:1.3;font-weight:800;">
          Uma atividade aguarda a sua aprovação
        </h1>
        @if(!empty($projectName))
        <p style="margin:0;color:#D4D4D8;font-size:14px;line-height:1.55;">Projeto <b style="color:#FFFFFF;">{{ $projectName }}</b></p>
        @endif
      </td>
    </tr>

    <tr>
      <td style="padding:18px 40px 0;background:#000000;">
        <div style="background-color:#15151A;border:1px solid #3F3F46;border-radius:12px;padding:22px;">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size:13px;line-height:1.7;">
            <tr>
              <td style="color:#D4D4D8;width:34%;padding:5px 0;font-weight:600;">Atividade</td>
              <td style="color:#FFFFFF;padding:5px 0;font-weight:700;">{{ $d->title }}</td>
            </tr>
            @if(!empty($stageName))
            <tr>
              <td style="color:#D4D4D8;padding:5px 0;font-weight:600;">Etapa</td>
              <td style="color:#FFFFFF;padding:5px 0;font-weight:700;">{{ $stageName }}</td>
            </tr>
            @endif
            @if(!empty($requestedBy))
            <tr>
              <td style="color:#D4D4D8;padding:5px 0;font-weight:600;">Solicitado por</td>
              <td style="color:#FFFFFF;padding:5px 0;font-weight:700;">{{ $requestedBy }}</td>
            </tr>
            @endif
            <tr>
              <td style="color:#D4D4D8;padding:5px 0;font-weight:600;">Quando</td>
              <td style="color:#FFFFFF;padding:5px 0;font-weight:700;">{{ optional($d->approval_requested_at ?? now())->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
            </tr>
          </table>
          @if(!empty($message))
          <div style="margin-top:16px;padding:14px 16px;background:#000000;border:1px solid #3F3F46;border-radius:10px;color:#FFFFFF;font-size:14px;line-height:1.6;white-space:pre-wrap;">{{ $message }}</div>
          @endif
        </div>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 8px;background:#000000;">
        <a href="{{ $url }}" style="display:inline-block;background:#FBBF24;color:#000000;text-decoration:none;font-weight:800;font-size:13px;padding:13px 26px;border-radius:8px;">Abrir e aprovar no Minutor</a>
        <p style="margin:14px 0 0;color:#A1A1AA;font-size:12px;line-height:1.6;">
          No Minutor, a atividade abre direto no acompanhamento do projeto. Você pode <b style="color:#D4D4D8;">Aprovar</b>
          ou <b style="color:#D4D4D8;">Solicitar ajustes</b>, com um comentário opcional.
        </p>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 32px;background:#000000;color:#D4D4D8;font-size:12px;line-height:1.7;border-top:1px solid #3F3F46;">
        Olá{{ !empty($recipientName) ? ', ' . $recipientName : '' }}. Você está recebendo este e-mail porque acompanha este projeto no Minutor.
        <br><br>
        <span style="color:#71717A;">&copy; {{ date('Y') }} {{ \App\Support\Brand::name() }} · Todos os direitos reservados</span>
      </td>
    </tr>
  </table>

</div>
</body>
</html>
