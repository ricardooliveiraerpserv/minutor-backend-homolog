<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Senha Temporária - Minutor</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 30px;
        }
        .password-box {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        .password-text {
            font-size: 24px;
            font-weight: bold;
            color: #495057;
            letter-spacing: 2px;
            font-family: 'Courier New', monospace;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
        }
        .warning-icon {
            color: #856404;
            font-weight: bold;
        }
        .button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            margin: 20px 0;
        }
        .instructions {
            background: #e8f4f8;
            border: 1px solid #bee5eb;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        .instructions h3 {
            margin-top: 0;
            color: #0c5460;
        }
        .instructions ol {
            margin: 10px 0;
            padding-left: 20px;
        }
        .instructions li {
            margin: 8px 0;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #6c757d;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Nova Senha Temporária</h1>
            <p>Sistema Minutor</p>
        </div>
        
        <div class="content">
            <p>Olá, <strong>{{ $notifiable->name }}</strong>!</p>
            
            <p>Você solicitou a recuperação de sua senha. Sua nova senha temporária foi gerada com sucesso.</p>
            
            <div class="password-box">
                <p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">Sua senha temporária é:</p>
                <div class="password-text">{{ $temporaryPassword }}</div>
            </div>
            
            <div class="warning">
                <div class="warning-icon">⚠️ IMPORTANTE:</div>
                <ul style="margin: 10px 0;">
                    <li>Esta senha é <strong>temporária</strong> e expira em <strong>{{ $expiresInHours }} horas</strong></li>
                    <li>Por motivos de segurança, você será obrigado a alterar sua senha no primeiro login</li>
                    <li>Não compartilhe esta senha com ninguém</li>
                    <li>Se você não solicitou esta alteração, entre em contato conosco imediatamente</li>
                </ul>
            </div>
            
            <div style="text-align: center;">
                <a href="{{ url('/login') }}" class="button">Fazer Login Agora</a>
            </div>
            
            <div class="instructions">
                <h3>📋 Instruções para uso:</h3>
                <ol>
                    <li>Acesse o sistema usando seu email e a senha temporária acima</li>
                    <li>Você será redirecionado automaticamente para criar uma nova senha</li>
                    <li>Escolha uma senha segura com pelo menos 8 caracteres</li>
                    <li>Após trocar a senha, você poderá usar o sistema normalmente</li>
                </ol>
            </div>
            
            <p>Se você tiver alguma dúvida ou precisar de ajuda, não hesite em entrar em contato com nosso suporte.</p>
            
            <p>Atenciosamente,<br>
            <strong>Equipe Minutor</strong></p>
        </div>
        
        <div class="footer">
            <p>Este é um email automático. Não responda a esta mensagem.</p>
            <p>© {{ date('Y') }} Minutor. Todos os direitos reservados.</p>
        </div>
    </div>
</body>
</html>
