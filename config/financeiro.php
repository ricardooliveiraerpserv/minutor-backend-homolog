<?php

return [
    // Permite o gateway de cobrança SIMULADO mesmo com APP_ENV=production (ex.: DEV2 rodando
    // com APP_ENV=production). Em PRD real deve ficar false.
    'allow_fake_billing' => env('FIN_ALLOW_FAKE_BILLING', false),
];
