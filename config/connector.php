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
        // ALLOWLIST operacional: start (C4.1) + stop (C4.2) + restart (C4.3) + rpo_promote (C5.2, SÓ hot) +
        // rpo_rollback (C5.3, SÓ hot). compile/patch NÃO existem. rpo_* são baseados em TARGET (não appserver único).
        'types' => ['start', 'stop', 'restart', 'rpo_promote', 'rpo_rollback'],
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
        // C5.1 — FUNDAÇÃO de publicação de RPO (registro/target/qualificação/preview; ZERO execução).
        'rpo' => [
            // Allowlist de capabilities de publicação SUPORTADAS (name + contract_version). Versão desconhecida
            // → capability INDISPONÍVEL (fail-closed). Nenhum código C5.1 invoca a capability.
            'supported_capabilities' => [
                ['name' => 'rpo_publish', 'contract_version' => 1],
            ],
            // Política N-of-M de aprovação (snapshot gravado na operação). prod=2, homolog/default=1.
            'required_approvals' => [
                'prod' => (int) env('CONNECTOR_RPO_APPROVALS_PROD', 2),
                'default' => (int) env('CONNECTOR_RPO_APPROVALS_DEFAULT', 1),
            ],
            // C5.2/C5.2b — activation modes EXECUTÁVEIS: 'hot' (C5.2) + 'requires_restart' (C5.2b, SÓ rolling).
            // requires_stop_start permanece FORA. Fail-closed: modo fora desta lista → não executável.
            'executable_activation_modes' => array_values(array_filter(array_map('trim', explode(',', (string) env('CONNECTOR_RPO_EXEC_ACTIVATION_MODES', 'hot,requires_restart'))), 'strlen')),
            // C5.2b — restart_strategy EXECUTÁVEL: SÓ 'rolling' (simultaneous BLOQUEADO nesta versão).
            // AUSÊNCIA de strategy NUNCA seleciona simultaneous: fica FORA desta allowlist → não executável.
            'executable_restart_strategies' => array_values(array_filter(array_map('trim', explode(',', (string) env('CONNECTOR_RPO_EXEC_RESTART_STRATEGIES', 'rolling'))), 'strlen')),
        ],

        // C5.2 — rpo_promote (SÓ activation_mode=hot). Timeouts cobrem resolve/validate/stage/apply/observe.
        // hot NÃO para AppServer → sem proteção de último-AppServer e sem janela obrigatória (N-of-M + presença
        // ONLINE + capability + publish_unit + target consistente governam). Reconciliação = target INTEIRO.
        'rpo_promote' => [
            'operational_deadline' => (int) env('CONNECTOR_OP_RPO_DEADLINE', 180),
            'reconcile_window'     => (int) env('CONNECTOR_OP_RPO_RECONCILE_WINDOW', 300),
            // C5.2b — requires_restart tem OUTAGE (o restart derruba processos) → timeouts MAIORES (publish +
            // rolling de N membros) + JANELA de manutenção OBRIGATÓRIA + presença online + last-AppServer.
            // Distinto do hot (que não tem window/last-AppServer). Só ativo quando activation_mode=requires_restart.
            'requires_restart' => [
                'operational_deadline' => (int) env('CONNECTOR_OP_RPO_RR_DEADLINE', 600),
                'reconcile_window'     => (int) env('CONNECTOR_OP_RPO_RR_RECONCILE_WINDOW', 600),
                // Rolling exige ≥ min_available membros observados up durante cada etapa (topologia de
                // DISPONIBILIDADE, distinta da unidade física de publicação). Target de 1 membro → outage
                // inevitável → last-AppServer bloqueia sem override.
                'min_available' => (int) env('CONNECTOR_OP_RPO_RR_MIN_AVAILABLE', 1),
                'window' => [
                    'enabled'  => filter_var(env('CONNECTOR_OP_RPO_RR_WINDOW_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
                    'timezone' => env('CONNECTOR_OP_RPO_RR_WINDOW_TZ', 'America/Sao_Paulo'),
                    'days'     => array_map('intval', array_filter(explode(',', (string) env('CONNECTOR_OP_RPO_RR_WINDOW_DAYS', '0,1,2,3,4,5,6')), 'strlen')),
                    'start'    => env('CONNECTOR_OP_RPO_RR_WINDOW_START', '00:00'),
                    'end'      => env('CONNECTOR_OP_RPO_RR_WINDOW_END', '23:59'),
                ],
            ],
        ],
        // C5.3 — rpo_rollback (SÓ hot): MESMA transição física hot from→to do promote; muda a AUTORIDADE do
        // destino (qualificação known_good CONTEXTUAL válida, nomeada por qualification_id). Reusa timeouts.
        'rpo_rollback' => [
            'operational_deadline' => (int) env('CONNECTOR_OP_RPO_DEADLINE', 180),
            'reconcile_window'     => (int) env('CONNECTOR_OP_RPO_RECONCILE_WINDOW', 300),
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
