<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bem-vindo(a) ao {{ config('app.name') }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f4f4;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            font-weight: 300;
        }
        
        .header p {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .welcome-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        .content {
            padding: 40px 30px;
        }
        
        .greeting {
            font-size: 24px;
            margin-bottom: 20px;
            color: #2c3e50;
            text-align: center;
        }
        
        .message {
            font-size: 16px;
            margin-bottom: 25px;
            color: #555;
            text-align: center;
        }
        
        .features {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 30px;
            margin: 30px 0;
        }
        
        .features h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 20px;
            text-align: center;
        }
        
        .feature-list {
            list-style: none;
        }
        
        .feature-list li {
            margin-bottom: 15px;
            padding-left: 30px;
            position: relative;
            font-size: 15px;
            color: #555;
        }
        
        .feature-list li::before {
            content: '✅';
            position: absolute;
            left: 0;
            top: 0;
            font-size: 16px;
        }
        
        .button {
            text-align: center;
            margin: 30px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 15px 35px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 500;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .credentials {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 5px 5px 0;
        }
        
        .credentials h4 {
            color: #1976d2;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .credentials p {
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .credentials strong {
            color: #333;
        }
        
        .next-steps {
            background-color: #fff3e0;
            border-left: 4px solid #ff9800;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 5px 5px 0;
        }
        
        .next-steps h4 {
            color: #f57c00;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .next-steps ol {
            margin-left: 20px;
        }
        
        .next-steps li {
            margin-bottom: 8px;
            font-size: 14px;
            color: #666;
        }
        
        .footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        
        .footer p {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .footer .company {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .social-links {
            margin-top: 20px;
        }
        
        .social-links a {
            color: #28a745;
            text-decoration: none;
            margin: 0 10px;
            font-size: 14px;
        }
        
        @media (max-width: 600px) {
            .container {
                margin: 10px;
                border-radius: 5px;
            }
            
            .content {
                padding: 20px;
            }
            
            .header {
                padding: 30px 20px;
            }
            
            .header h1 {
                font-size: 28px;
            }
            
            .greeting {
                font-size: 20px;
            }
            
            .features {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="welcome-icon">🎉</div>
            <h1>Bem-vindo(a)!</h1>
            <p>Sua conta foi criada com sucesso</p>
        </div>
        
        <div class="content">
            <div class="greeting">
                Olá, {{ $user->name ?? 'Usuário' }}!
            </div>
            
            <div class="message">
                É um prazer ter você conosco no <strong>{{ config('app.name') }}</strong>. Sua conta foi criada com sucesso e você já pode começar a usar nosso sistema de gestão empresarial.
            </div>
            
            @if(isset($temporaryPassword))
            <div class="credentials">
                <h4>🔐 Suas credenciais de acesso:</h4>
                <p><strong>Email:</strong> {{ $user->email }}</p>
                <p><strong>Senha temporária:</strong> {{ $temporaryPassword }}</p>
                <p style="margin-top: 15px; color: #d32f2f; font-size: 13px;">
                    <strong>⚠️ Importante:</strong> Altere sua senha no primeiro acesso por segurança.
                </p>
            </div>
            @endif
            
            <div class="features">
                <h3>🚀 O que você pode fazer agora:</h3>
                <ul class="feature-list">
                    <li>Gerenciar projetos e clientes</li>
                    <li>Controlar horas trabalhadas</li>
                    <li>Acompanhar despesas</li>
                    <li>Gerar relatórios detalhados</li>
                    <li>Colaborar com sua equipe</li>
                    <li>Monitorar performance</li>
                </ul>
            </div>
            
            <div class="button">
                <a href="{{ config('app.frontend_url') }}" class="btn">Acessar o Sistema</a>
            </div>
            
            <div class="next-steps">
                <h4>📋 Primeiros passos recomendados:</h4>
                <ol>
                    <li>Faça login no sistema</li>
                    <li>Complete seu perfil</li>
                    <li>Configure suas preferências</li>
                    <li>Explore as funcionalidades</li>
                    <li>Entre em contato se precisar de ajuda</li>
                </ol>
            </div>
        </div>
        
        <div class="footer">
            <p class="company">{{ config('app.name') }}</p>
            <p>Sistema de Gestão Empresarial</p>
            <p>Precisa de ajuda? Entre em contato com nosso suporte.</p>
            
            <div class="social-links">
                <a href="#">Documentação</a>
                <a href="#">Suporte</a>
                <a href="#">FAQ</a>
            </div>
        </div>
    </div>
</body>
</html>
