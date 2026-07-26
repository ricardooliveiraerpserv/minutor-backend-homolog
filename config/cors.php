<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // B3 (segurança): em produção o default traz SÓ as origens oficiais.
    // Origens de teste/homolog (onrender/localhost) só entram fora de produção
    // ou explicitamente via env CORS_ALLOWED_ORIGINS.
    'allowed_origins' => array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        env('APP_ENV') === 'production'
            ? 'https://app.minutor.com.br,https://api.minutor.com.br'
            : 'https://app.minutor.com.br,https://api.minutor.com.br,https://minutor-frontend-homolog.onrender.com,https://minutor-backend-homolog.onrender.com,http://localhost:3000,http://localhost:5173'
    )))),

    'allowed_origins_patterns' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS_PATTERNS', '')))),

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-CSRF-TOKEN', 'X-XSRF-TOKEN', 'X-Admin-Key'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,

];
