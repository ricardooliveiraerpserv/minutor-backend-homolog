<?php

return [
    /*
     | Liga o isolamento automático por empresa (global scope + carimbo do
     | company_id ao criar). Fica DESLIGADO por padrão: enquanto false, os
     | models com o trait BelongsToCompany se comportam exatamente como antes.
     | Ligar só quando o piloto estiver validado.
     */
    'scoping_enabled' => env('MULTIEMPRESA_SCOPING', false),
];
