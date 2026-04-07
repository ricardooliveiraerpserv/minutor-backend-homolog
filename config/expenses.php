<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Configurações de Despesas
    |--------------------------------------------------------------------------
    |
    | Configurações relacionadas ao sistema de despesas
    |
    */

    // Período em horas para permitir estorno de aprovação
    'reversal_period_hours' => env('EXPENSE_REVERSAL_PERIOD_HOURS', 24),

    // Tamanho máximo de arquivo para comprovantes (em MB)
    'max_receipt_size' => env('EXPENSE_MAX_RECEIPT_SIZE', 5),

    // Tipos de arquivo permitidos para comprovantes
    'allowed_receipt_types' => [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
    ],

    // Valor máximo para despesas (sem aprovação especial)
    'max_amount_without_approval' => env('EXPENSE_MAX_AMOUNT_WITHOUT_APPROVAL', 1000.00),
];
