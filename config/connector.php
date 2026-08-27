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
];
