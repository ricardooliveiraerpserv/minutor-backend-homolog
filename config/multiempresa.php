<?php

return [
    /*
     | Liga o isolamento automático por empresa (global scope + carimbo do
     | company_id ao criar). Enquanto false, os models com BelongsToCompany se
     | comportam como antes.
     |
     | DEFAULT seguro por ambiente: LIGADO no HOMOLOG (APP_URL em *.onrender.com),
     | DESLIGADO em produção (api.minutor.com.br) e local — mesmo se este código
     | vazar pra prod. A env MULTIEMPRESA_SCOPING sempre sobrepõe (local usa .env=true).
     */
    'scoping_enabled' => env(
        'MULTIEMPRESA_SCOPING',
        str_contains((string) env('APP_URL', ''), 'onrender')          // FE/BE em Render (homolog)
            || str_contains((string) env('DB_HOST', ''), 'supabase')   // banco Supabase (homolog)
            || str_contains((string) env('DATABASE_URL', ''), 'supabase'),
    ),
];
