<?php

return [
    // Permite o gateway de cobrança SIMULADO mesmo com APP_ENV=production (ex.: DEV2 rodando
    // com APP_ENV=production). Em PRD real deve ficar false.
    'allow_fake_billing' => env('FIN_ALLOW_FAKE_BILLING', false),

    // Emissor de NFS-e: simulado (sem rede, só homologação) | nacional (API do Sistema Nacional NFS-e).
    'nfse_emissor' => env('FIN_NFSE_EMISSOR', 'simulado'),

    // Trava de segurança: emitir NFS-e em PRODUÇÃO (valor fiscal real). Nunca ligar na DEV2.
    'nfse_permitir_producao' => (bool) env('FIN_NFSE_PERMITIR_PRODUCAO', false),
];
