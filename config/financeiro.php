<?php

return [
    // Permite o gateway de cobrança SIMULADO mesmo com APP_ENV=production (ex.: DEV2 rodando
    // com APP_ENV=production). Em PRD real deve ficar false.
    'allow_fake_billing' => env('FIN_ALLOW_FAKE_BILLING', false),

    // Emissor de NFS-e: simulado (sem rede, só homologação) | nacional (API do Sistema Nacional NFS-e).
    'nfse_emissor' => env('FIN_NFSE_EMISSOR', 'simulado'),

    // Trava de segurança: emitir NFS-e em PRODUÇÃO (valor fiscal real). Nunca ligar na DEV2.
    'nfse_permitir_producao' => (bool) env('FIN_NFSE_PERMITIR_PRODUCAO', false),

    // Assinatura XMLDSig da DPS: sha1 (padrão DF-e) | sha256.
    'nfse_assinatura' => env('FIN_NFSE_ASSINATURA', 'sha1'),

    // Informar inscrição municipal de prestador/tomador na DPS (desligar se o município rejeitar).
    'nfse_informar_im' => (bool) env('FIN_NFSE_INFORMAR_IM', true),

    // SEFIN Nacional (Manual dos Contribuintes — API do Emissor Público). Bases configuráveis.
    'nfse_api' => [
        'homologacao' => env('FIN_NFSE_API_HOMOLOGACAO', 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional'),
        'producao' => env('FIN_NFSE_API_PRODUCAO', 'https://sefin.nfse.gov.br/SefinNacional'),
        'campo_dps' => env('FIN_NFSE_CAMPO_DPS', 'dpsXmlGZipB64'),
    ],
];
