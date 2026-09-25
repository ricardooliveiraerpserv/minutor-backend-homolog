<?php

return [
    // Motor padrão da Plataforma de Documentos.
    'default_renderer' => env('DOCUMENTS_RENDERER', 'chromium'),

    // Serviço de render Chromium (Gotenberg) — isolado, via HTTP. Backend PHP sem Node interno.
    'gotenberg' => [
        'url'     => env('GOTENBERG_URL', 'http://localhost:3050'),
        'timeout' => (int) env('GOTENBERG_TIMEOUT', 60),
    ],

    // Disco de armazenamento dos PDFs (via camada Attachment FASE 11).
    'disk' => env('DOCUMENTS_DISK', 'local'),

    // Fila DEDICADA (contexto oficial item 4): documentos NUNCA misturam com CRM/scheduler/integrações.
    'queue' => env('DOCUMENTS_QUEUE', 'documents'),

    // Limites de tamanho do PDF (item 4): alerta e bloqueio.
    'limits' => [
        'alert_mb' => (int) env('DOCUMENTS_ALERT_MB', 20),  // > 20 MB → alerta (log warning)
        'block_mb' => (int) env('DOCUMENTS_BLOCK_MB', 30),  // > 30 MB → bloqueia (exige allow_large)
    ],
];
