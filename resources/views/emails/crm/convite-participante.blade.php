<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Convite — Proposta{{ $codigo ? ' ' . $codigo : '' }}</title>
  <style>
    body { margin:0 !important; padding:0 !important; background-color:#F4F5F7; }
    img { border:0; outline:none; text-decoration:none; }
    @media only screen and (max-width: 600px) {
      .wrapper { width:100% !important; }
      .card { border-radius:12px !important; margin:0 12px !important; }
      .pd-main { padding:28px 22px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#F4F5F7;font-family:'Segoe UI',Arial,sans-serif;">
  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#F4F5F7" style="background-color:#F4F5F7;">
    <tr>
      <td align="center" style="padding:32px 12px;">
        <table role="presentation" class="wrapper" border="0" cellpadding="0" cellspacing="0" width="600" style="width:600px;max-width:600px;">
          <tr>
            <td class="card" style="background-color:#FFFFFF;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(16,24,40,0.08);">

              <!-- Header -->
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td style="background-color:#0B1F33;padding:30px 36px 24px;">
                    <div style="color:#9FB3C8;font-size:12px;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;">Proposta Comercial</div>
                    <div style="color:#FFFFFF;font-size:24px;font-weight:700;line-height:1.2;">
                      {{ $isReenvio ? 'Lembrete do seu convite' : 'Você foi convidado(a)' }}
                    </div>
                  </td>
                </tr>
              </table>

              <!-- Main -->
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td class="pd-main" style="padding:34px 36px 14px;color:#1A2A3A;font-size:15px;line-height:1.62;">
                    <p style="margin:0 0 16px;">Olá, <strong>{{ $participantName }}</strong>.</p>
                    <p style="margin:0 0 16px;">
                      A <strong>{{ $senderName }}</strong> disponibilizou para você a proposta comercial
                      @if($codigo)<strong>{{ $codigo }}</strong>@endif
                      @if($clienteName) referente a <strong>{{ $clienteName }}</strong>@endif.
                      Você está participando como <strong>{{ $papeisLabel }}</strong>.
                    </p>

                    @if(trim((string) $mensagem) !== '')
                      <div style="margin:0 0 18px;padding:14px 16px;background-color:#F4F7FB;border-left:3px solid #0B5FFF;border-radius:8px;color:#334155;font-size:14px;white-space:pre-line;">{{ $mensagem }}</div>
                    @endif

                    <!-- Resumo -->
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:6px 0 20px;border:1px solid #E5EAF0;border-radius:10px;">
                      @if($tipoLabel)
                      <tr><td style="padding:10px 16px;color:#64748B;font-size:13px;border-bottom:1px solid #EEF1F5;width:42%;">Modalidade</td><td style="padding:10px 16px;color:#1A2A3A;font-size:13px;font-weight:600;border-bottom:1px solid #EEF1F5;">{{ $tipoLabel }}</td></tr>
                      @endif
                      @if($valorTotal)
                      <tr><td style="padding:10px 16px;color:#64748B;font-size:13px;border-bottom:1px solid #EEF1F5;">Valor</td><td style="padding:10px 16px;color:#1A2A3A;font-size:13px;font-weight:600;border-bottom:1px solid #EEF1F5;">{{ $valorTotal }}</td></tr>
                      @endif
                      @if($validade)
                      <tr><td style="padding:10px 16px;color:#64748B;font-size:13px;">Validade</td><td style="padding:10px 16px;color:#1A2A3A;font-size:13px;font-weight:600;">{{ $validade }}</td></tr>
                      @endif
                    </table>

                    <!-- CTA -->
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                      <tr>
                        <td align="center" style="padding:6px 0 10px;">
                          <a href="{{ $portalUrl }}" target="_blank"
                             style="display:inline-block;background-color:#0B5FFF;color:#FFFFFF;font-size:15px;font-weight:600;text-decoration:none;padding:14px 34px;border-radius:10px;">
                            Acessar a proposta
                          </a>
                        </td>
                      </tr>
                    </table>
                    <p style="margin:14px 0 0;color:#94A3B8;font-size:12px;line-height:1.5;text-align:center;">
                      Este link é pessoal e identifica o seu acesso. Não o repasse.
                    </p>
                  </td>
                </tr>
              </table>

              <!-- Footer -->
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td style="padding:18px 36px 28px;border-top:1px solid #EEF1F5;color:#94A3B8;font-size:12px;line-height:1.5;">
                    Em caso de dúvidas, basta responder a este e-mail.
                  </td>
                </tr>
              </table>

            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
