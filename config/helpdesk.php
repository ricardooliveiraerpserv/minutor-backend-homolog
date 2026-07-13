<?php

return [
    // Mesclagem de chamados.
    'merge' => [
        // Teto de interações (comments) SOMADAS entre os chamados a mesclar — evita chamados gigantes.
        'max_actions' => (int) env('HELPDESK_MERGE_MAX_ACTIONS', 500),
    ],
];
