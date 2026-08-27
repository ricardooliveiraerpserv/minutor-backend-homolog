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

    // Connector-4.x — OPERAÇÕES destrutivas/controladas (classe de segurança separada).
    'operations' => [
        // ALLOWLIST operacional: start (C4.1) + stop (C4.2) + restart (C4.3). compile/patch/RPO NÃO existem.
        'types' => ['start', 'stop', 'restart'],
        // Maker-checker OBRIGATÓRIO — inclusive homolog (só o gate controlado poderia relaxar).
        'require_approval' => filter_var(env('CONNECTOR_OP_REQUIRE_APPROVAL', true), FILTER_VALIDATE_BOOLEAN),
        // Transport lease: dispatchable→claim. Vence SEM claim → expired (único timeout auto-terminal seguro).
        'transport_lease' => (int) env('CONNECTOR_OP_TRANSPORT_LEASE', 60),
        // Frescor exigido do observado C-2 na pré-condição (segundos).
        'observed_freshness' => (int) env('CONNECTOR_OP_OBSERVED_FRESHNESS', 120),
        'start' => [
            'operational_deadline' => (int) env('CONNECTOR_OP_START_DEADLINE', 120), // timeout OPERACIONAL
            'reconcile_window'     => (int) env('CONNECTOR_OP_RECONCILE_WINDOW', 180),
        ],
        // C4.2 — stop: indisponibilidade DELIBERADA. Gates próprios (janela obrigatória, presença online
        // estrita, proteção do ÚLTIMO AppServer up), revalidados no dispatch.
        'stop' => [
            'operational_deadline' => (int) env('CONNECTOR_OP_STOP_DEADLINE', 120),
            'reconcile_window'     => (int) env('CONNECTOR_OP_STOP_RECONCILE_WINDOW', 180),
            // Proteção do último AppServer: exige ≥ min_other_up AppServers UP (além do alvo) na MESMA
            // observação fresca. min_other_up=1 (primeiro release). Bloqueio salvo emergency_override.
            'min_other_up' => (int) env('CONNECTOR_OP_STOP_MIN_OTHER_UP', 1),
            // Janela de manutenção OBRIGATÓRIA (política em CONFIG, sem tabela). Calculada server-side e
            // GRAVADA na operação (não depende de config mutável depois p/ explicar autorização antiga).
            'window' => [
                'enabled'  => filter_var(env('CONNECTOR_OP_STOP_WINDOW_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
                'timezone' => env('CONNECTOR_OP_STOP_WINDOW_TZ', 'America/Sao_Paulo'),
                'days'     => array_map('intval', array_filter(explode(',', (string) env('CONNECTOR_OP_STOP_WINDOW_DAYS', '0,1,2,3,4,5,6')), 'strlen')), // 0=Dom..6=Sáb
                'start'    => env('CONNECTOR_OP_STOP_WINDOW_START', '00:00'),
                'end'      => env('CONNECTOR_OP_STOP_WINDOW_END', '23:59'),
            ],
        ],
        // C4.3 — restart: down transiente → up(B). MESMOS gates do stop (último AppServer/janela/presença
        // online, revalidados no dispatch). Timeouts MAIORES (cobre down+startup). Sucesso FORTE = up(B),
        // B≠A, evidenciado por coleta de reconciliação CORRELACIONADA (trigger.operation_id).
        'restart' => [
            'operational_deadline' => (int) env('CONNECTOR_OP_RESTART_DEADLINE', 300),
            'reconcile_window'     => (int) env('CONNECTOR_OP_RESTART_RECONCILE_WINDOW', 300),
            'min_other_up' => (int) env('CONNECTOR_OP_RESTART_MIN_OTHER_UP', 1),
            'window' => [
                'enabled'  => filter_var(env('CONNECTOR_OP_RESTART_WINDOW_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
                'timezone' => env('CONNECTOR_OP_RESTART_WINDOW_TZ', 'America/Sao_Paulo'),
                'days'     => array_map('intval', array_filter(explode(',', (string) env('CONNECTOR_OP_RESTART_WINDOW_DAYS', '0,1,2,3,4,5,6')), 'strlen')),
                'start'    => env('CONNECTOR_OP_RESTART_WINDOW_START', '00:00'),
                'end'      => env('CONNECTOR_OP_RESTART_WINDOW_END', '23:59'),
            ],
        ],
    ],
];
