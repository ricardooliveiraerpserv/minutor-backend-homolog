<?php

/**
 * Trilha Conector Protheus — Connector-0. Parâmetros do canal seguro (enrollment + identidade
 * Ed25519 + assinatura + replay). SÓ identidade/canal nesta subfase — sem heartbeat/estado/comando.
 */
return [
    // Enrollment token: uso único, curta validade.
    'enrollment_ttl' => (int) env('CONNECTOR_ENROLLMENT_TTL', 900), // 15 min

    // Replay protection.
    'clock_skew' => (int) env('CONNECTOR_CLOCK_SKEW', 300),  // ±5 min
    'nonce_ttl'  => (int) env('CONNECTOR_NONCE_TTL', 600),   // > clock_skew

    // Proteção antes da verificação criptográfica.
    'max_agent_body_bytes' => (int) env('CONNECTOR_MAX_BODY', 8192),

    // Cache de nonce: DEVE ser compartilhado entre instâncias em produção (redis/database),
    // NUNCA array/file por-processo. null = usa o store default do app. Fail-closed se indisponível.
    'nonce_store' => env('CONNECTOR_NONCE_STORE'),

    // Connector-1 — presença/heartbeat. Status derivado SÓ de last_seen_at (received_at do backend).
    'heartbeat_interval' => (int) env('CONNECTOR_HEARTBEAT_INTERVAL', 60),
    'grace'              => (int) env('CONNECTOR_HEARTBEAT_GRACE', 15),
    'presence_online'    => (int) env('CONNECTOR_PRESENCE_ONLINE', 75),   // Δ ≤ 75s → online
    'presence_offline'   => (int) env('CONNECTOR_PRESENCE_OFFLINE', 300), // Δ > 300s → offline; entre = stale
    'clock_offset_warn'  => (int) env('CONNECTOR_CLOCK_OFFSET_WARN', 120), // |offset| acima → degraded/diagnóstico

    // Connector-3 — comandos assíncronos NÃO destrutivos (agente = worker; long-poll outbound-only).
    'commands' => [
        // ALLOWLIST de tipos aceitos NESTA FASE. NADA de start/stop/restart/compile/patch/promote/rollback.
        'types' => ['collect_inventory_now'],
        // LEASE do claim: janela p/ o agente executar+reportar. Curto p/ collect (rápido/idempotente).
        'claim_lease' => (int) env('CONNECTOR_CMD_LEASE', 15),
        // TTL DURO: queued nunca reivindicado expira (não executa horas depois).
        'ttl' => (int) env('CONNECTOR_CMD_TTL', 120),
        // attempts incrementa NO CLAIM; max_attempts=2 = um único retry controlado.
        'max_attempts' => (int) env('CONNECTOR_CMD_MAX_ATTEMPTS', 2),
        // Long-poll: hold do GET /connector/commands/next. CONFIGURÁVEL ATÉ 0 (short-poll) sem mudar contrato.
        'longpoll_hold' => (int) env('CONNECTOR_CMD_LONGPOLL_HOLD', 25),
        // Debounce/coalescing de solicitações sem idempotency_key explícita (evita tempestade de coletas).
        'debounce' => (int) env('CONNECTOR_CMD_DEBOUNCE', 30),
        // Retenção operacional (a auditoria durável fica em connector_events/timeline).
        'retention_days' => (int) env('CONNECTOR_CMD_RETENTION_DAYS', 60),
    ],
];
