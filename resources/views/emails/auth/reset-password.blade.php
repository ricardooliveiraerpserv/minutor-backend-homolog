<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperação de Senha</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    
    <div style="background-color: #667eea; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0;">
        <h1 style="margin: 0; font-size: 24px;">{{ config('app.name') }}</h1>
        <p style="margin: 5px 0 0 0;">Recuperação de Senha</p>
    </div>
    
    <div style="background-color: #f9f9f9; padding: 30px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 5px 5px;">
        
        <p style="margin: 0 0 20px 0; font-size: 16px;">Olá, {{ $user->name ?? 'Usuário' }}!</p>
        
        <p style="margin: 0 0 20px 0;">Você solicitou a recuperação de senha para sua conta no {{ config('app.name') }}.</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $resetUrl }}" style="background-color: #667eea; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Redefinir Senha</a>
        </div>
        
        <p style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; font-size: 14px;">
            <strong>Importante:</strong> Este link expira em {{ $validMinutes ?? 60 }} minutos.
        </p>
        
        <p style="margin: 20px 0 0 0; font-size: 14px;">Se você não solicitou esta recuperação, ignore este email.</p>
        
        <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">
        
        <p style="margin: 0; font-size: 12px; color: #666; text-align: center;">
            {{ config('app.name') }} - Sistema de Gestão<br>
            Este é um email automático, não responda.
        </p>
        
    </div>
    
</body>
</html>
