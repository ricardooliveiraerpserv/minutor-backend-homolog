<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  {{-- Tema CLARO: legível em qualquer cliente (light/dark). O tema escuro forçado sumia o
       texto branco quando o cliente removia o fundo preto (branco no branco). --}}
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>Redefinição de Senha — Minutor</title>
  <!--[if mso]>
  <noscript>
    <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
  </noscript>
  <![endif]-->
  <style>
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; display: block; }
    body { margin: 0 !important; padding: 0 !important; background-color: #f4f5f7; }
    a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
    @media only screen and (max-width: 620px) {
      .wrapper { width: 100% !important; }
      .card { border-radius: 12px !important; margin: 0 12px !important; }
      .pd-main { padding: 28px 20px !important; }
      .pd-header { padding: 26px 20px !important; }
      .btn-td { padding: 28px 20px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:'Segoe UI',Arial,sans-serif;">

  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
    style="background-color:#f4f5f7;">
    <tr>
      <td align="center" style="padding:32px 16px;">

        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"
          class="wrapper card"
          style="background-color:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e6e8ec;box-shadow:0 12px 34px rgba(17,24,39,0.08);">

          {{-- ── HEADER (faixa colorida + logo branco) ── --}}
          <tr>
            <td class="pd-header" align="center"
              style="padding:30px 40px;background-color:#7c3aed;">
              <a href="https://erpserv.com.br" target="_blank" style="text-decoration:none;">
                <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('logo-erpserv-white.png'))) }}"
                  alt="ERPServ Consultoria"
                  width="150" height="auto"
                  style="display:inline-block;width:150px;height:auto;" />
              </a>
              <div style="margin-top:14px;font-size:22px;font-weight:700;letter-spacing:-0.01em;color:#ffffff;line-height:1.1;font-family:'Segoe UI',Arial,sans-serif;">
                Minutor
              </div>
              <div style="margin-top:3px;font-size:13px;color:rgba(255,255,255,0.75);font-weight:400;font-family:'Segoe UI',Arial,sans-serif;">
                Controle de horas e contratos em um só lugar
              </div>
            </td>
          </tr>

          {{-- ── SAUDAÇÃO ── --}}
          <tr>
            <td class="pd-main" align="left" style="padding:36px 40px 0;">
              <h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;
                font-family:'Segoe UI',Arial,sans-serif;line-height:1.3;">
                Redefinição de Senha
              </h1>
              <p style="margin:0;font-size:15px;color:#374151;line-height:1.6;
                font-family:'Segoe UI',Arial,sans-serif;">
                Olá, <strong style="color:#111827;">{{ $user->name ?? 'Usuário' }}</strong>!
                Recebemos uma solicitação para redefinir a senha da sua conta no Minutor.
              </p>
            </td>
          </tr>

          {{-- ── BOTÃO REDEFINIR ── --}}
          <tr>
            <td class="btn-td" align="center" style="padding:32px 40px 0;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center"
                    style="border-radius:10px;background:linear-gradient(135deg,#3B82F6 0%,#8B5CF6 100%);background-color:#7c3aed;">
                    <a href="{{ $resetUrl }}"
                      target="_blank"
                      style="display:inline-block;padding:14px 36px;font-size:15px;font-weight:600;
                        color:#FFFFFF;text-decoration:none;font-family:'Segoe UI',Arial,sans-serif;
                        letter-spacing:0.2px;">
                      Redefinir Senha &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- ── ALERTA ── --}}
          <tr>
            <td style="padding:28px 40px 0;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"
                style="background-color:#fffbeb;border-radius:8px;border:1px solid #fde68a;">
                <tr>
                  <td style="padding:14px 16px;">
                    <span style="font-size:13px;color:#92400e;font-family:'Segoe UI',Arial,sans-serif;
                      font-weight:700;display:block;margin-bottom:6px;">⚠️ IMPORTANTE</span>
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                      @foreach([
                        'Este link expira em <strong>' . ($validMinutes ?? 60) . ' minutos</strong>',
                        'Se você não solicitou esta redefinição, ignore este e-mail',
                        'Sua senha atual continuará funcionando até você criar uma nova',
                      ] as $aviso)
                      <tr>
                        <td style="padding:3px 0;">
                          <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                            <tr>
                              <td style="vertical-align:top;padding-right:8px;width:14px;">
                                <span style="font-size:13px;color:#b45309;">•</span>
                              </td>
                              <td>
                                <span style="font-size:13px;color:#92400e;font-family:'Segoe UI',Arial,sans-serif;line-height:1.5;">
                                  {!! $aviso !!}
                                </span>
                              </td>
                            </tr>
                          </table>
                        </td>
                      </tr>
                      @endforeach
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- ── LINK ALTERNATIVO ── --}}
          <tr>
            <td style="padding:20px 40px 0;">
              <p style="margin:0;font-size:12px;color:#6b7280;font-family:'Segoe UI',Arial,sans-serif;line-height:1.6;">
                Se o botão não funcionar, copie e cole o link abaixo no seu navegador:<br>
                <a href="{{ $resetUrl }}" style="color:#6d28d9;word-break:break-all;">{{ $resetUrl }}</a>
              </p>
            </td>
          </tr>

          <tr><td style="padding-bottom:32px;"></td></tr>

          {{-- ── DIVISOR ── --}}
          <tr>
            <td style="padding:0 40px;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr><td style="border-top:1px solid #eceef1;font-size:0;line-height:0;">&nbsp;</td></tr>
              </table>
            </td>
          </tr>

          {{-- ── RODAPÉ ── --}}
          <tr>
            <td align="center" style="padding:22px 40px 30px;">
              <span style="font-size:11px;color:#9ca3af;font-family:'Segoe UI',Arial,sans-serif;letter-spacing:0.02em;">
                &copy; {{ date('Y') }} ERPServ Consultoria &middot; Todos os direitos reservados
              </span>
            </td>
          </tr>

        </table>

        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" class="wrapper">
          <tr>
            <td align="center" style="padding-top:18px;">
              <span style="font-size:11px;color:#9ca3af;font-family:'Segoe UI',Arial,sans-serif;">
                Você está recebendo este e-mail porque uma redefinição de senha foi solicitada para sua conta.
              </span>
            </td>
          </tr>
        </table>

      </td>
    </tr>
  </table>

</body>
</html>
