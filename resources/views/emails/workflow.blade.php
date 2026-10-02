<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><title>{{ $titulo }}</title></head>
<body style="margin:0;padding:0;background:#000000;font-family:'Segoe UI',-apple-system,Helvetica,Arial,sans-serif;">
<div style="max-width:640px;margin:0 auto;padding:24px 16px;">

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
    style="background:#000000;border-radius:16px;overflow:hidden;border:1px solid #3F3F46;">

    @include('emails.cards._partial-header')

    <tr>
      <td style="padding:32px 40px 4px;background:#000000;">
        <div style="font-size:11px;letter-spacing:.22em;color:{{ $accent ?? '#22C55E' }};font-weight:800;text-transform:uppercase;">{{ $eyebrow }}</div>
        <h1 style="margin:8px 0 4px;color:#FFFFFF;font-size:23px;line-height:1.3;font-weight:800;">{{ $titulo }}</h1>
        <p style="margin:8px 0 0;color:#D4D4D8;font-size:14px;line-height:1.55;">{!! nl2br(e($corpo)) !!}</p>
      </td>
    </tr>

    @if(!empty($dados))
    <tr>
      <td style="padding:18px 40px 0;background:#000000;">
        <div style="background-color:#15151A;border:1px solid #3F3F46;border-radius:12px;padding:22px;">
          <div style="font-size:10px;letter-spacing:.24em;color:{{ $accent ?? '#22C55E' }};font-weight:800;text-transform:uppercase;margin-bottom:14px;">Variáveis de exemplo</div>
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
            @foreach($dados as $label => $valor)
            <tr>
              <td style="color:#D4D4D8;width:40%;padding:5px 0;font-weight:600;">{{ $label }}</td>
              <td style="color:#FFFFFF;padding:5px 0;font-weight:700;">{{ $valor }}</td>
            </tr>
            @endforeach
          </table>
        </div>
      </td>
    </tr>
    @endif

    <tr>
      <td style="padding:24px 40px 8px;background:#000000;">
        <a href="{{ $cardUrl }}" style="display:inline-block;background:{{ $accent ?? '#22C55E' }};color:#000000;text-decoration:none;font-weight:800;font-size:13px;padding:13px 26px;border-radius:8px;">Acessar o Minutor</a>
      </td>
    </tr>

    <tr>
      <td style="padding:24px 40px 32px;background:#000000;color:#D4D4D8;font-size:12px;line-height:1.7;border-top:1px solid #3F3F46;">
        {{ $rodape }}
        <br><br>
        <span style="color:#71717A;">&copy; {{ date('Y') }} ERPServ Consultoria · Todos os direitos reservados</span>
      </td>
    </tr>
  </table>

</div>
</body>
</html>
