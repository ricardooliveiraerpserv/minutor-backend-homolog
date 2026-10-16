<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'movidesk' => [
        'token'            => env('MOVIDESK_API_TOKEN'),
        'webhook_secret'   => env('MOVIDESK_WEBHOOK_SECRET'),
        'webhook_validate' => filter_var(env('MOVIDESK_WEBHOOK_VALIDATE', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'n8n' => [
        'timesheet_webhook_url'     => env('N8N_TIMESHEET_WEBHOOK_URL', 'https://erpserv.app.n8n.cloud/webhook/apontamento-status'),
        'timesheet_webhook_enabled' => filter_var(env('N8N_TIMESHEET_WEBHOOK_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'anthropic' => [
        'api_key'   => env('ANTHROPIC_API_KEY'),
        'model'     => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        'base_url'  => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'ocr_model' => env('ANTHROPIC_OCR_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    'openai' => [
        'api_key'  => env('OPENAI_API_KEY'),
        'model'    => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    'ai' => [
        'default_provider' => env('AI_DEFAULT_PROVIDER', 'anthropic'),
    ],

    /*
     * Microsoft Graph — leitura da caixa do noreply para puxar as RESPOSTAS dos
     * consultores ao fechamento (Fase 2). client-credentials (app-only).
     * Sem as 4 variáveis abaixo o leitor fica DORMENTE (não quebra nada).
     * Requer app registrado no Azure AD + permissão de aplicação Mail.Read
     * (idealmente escopada à caixa via Application Access Policy no Exchange).
     */
    'microsoft_graph' => [
        'tenant_id'     => env('GRAPH_TENANT_ID'),
        'client_id'     => env('GRAPH_CLIENT_ID'),
        'client_secret' => env('GRAPH_CLIENT_SECRET'),
        // Caixa lida (UPN/e-mail). Default = mesma conta do remetente (noreply).
        'mailbox'       => env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS')),
    ],

    /*
     * Microsoft Graph — ENVIO dos fechamentos pela caixa do REMETENTE logado
     * (Send As via permissão de aplicação Mail.Send escopada por Application Access
     * Policy). client-credentials (app-only), SEM SMTP / SEM app password.
     * Sem as 3 variáveis abaixo o envio via Graph fica DORMENTE — GraphMailer::enabled()
     * retorna false e o envio cai no fluxo SMTP/SenderMailer atual (idêntico a hoje).
     * Requer app registrado no Azure AD + permissão de aplicação Mail.Send.
     */
    'graph' => [
        // Fallback p/ GRAPH_* quando o ambiente não usa o prefixo MAIL_GRAPH_*.
        'tenant_id'     => env('MAIL_GRAPH_TENANT_ID', env('GRAPH_TENANT_ID')),
        'client_id'     => env('MAIL_GRAPH_CLIENT_ID', env('GRAPH_CLIENT_ID')),
        'client_secret' => env('MAIL_GRAPH_CLIENT_SECRET', env('GRAPH_CLIENT_SECRET')),
        // Caixa que envia notificações do sistema (comunicado de reajuste / alerta).
        'mailbox'       => env('MAIL_GRAPH_MAILBOX', env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS'))),
    ],

    // Microsoft Graph — credenciais app-only do leitor/enviador (Central de Notificações + Comunicações).
    'graph_reader' => [
        'tenant_id'     => env('GRAPH_TENANT_ID', env('MAIL_GRAPH_TENANT_ID')),
        'client_id'     => env('GRAPH_CLIENT_ID', env('MAIL_GRAPH_CLIENT_ID')),
        'client_secret' => env('GRAPH_CLIENT_SECRET', env('MAIL_GRAPH_CLIENT_SECRET')),
    ],

    // Outlook/Calendar do usuário (OAuth DELEGADO). Reusa o app do MAIL_GRAPH por padrão;
    // o app no Azure precisa ter o redirect_uri abaixo registrado + permissão delegada Calendars.Read.
    'microsoft_calendar' => [
        'tenant_id'     => env('MS_CALENDAR_TENANT_ID', env('MAIL_GRAPH_TENANT_ID')),
        'client_id'     => env('MS_CALENDAR_CLIENT_ID', env('MAIL_GRAPH_CLIENT_ID')),
        'client_secret' => env('MS_CALENDAR_CLIENT_SECRET', env('MAIL_GRAPH_CLIENT_SECRET')),
        'redirect_uri'  => env('MS_CALENDAR_REDIRECT_URI', rtrim(env('APP_URL', ''), '/') . '/api/v1/integrations/microsoft/callback'),
        // Calendars.ReadWrite (ler a agenda do Meu Dia + CRIAR evento/reunião Teams). Contas já conectadas
        // só com Calendars.Read seguem lendo normal (o refresh não pede escopo → mantém o consentido);
        // para CRIAR reunião é preciso reconectar a conta (novo consentimento com escrita).
        'scopes'        => env('MS_CALENDAR_SCOPES', 'offline_access openid profile Calendars.ReadWrite'),
    ],

    // Central de Reuniões. teams_enabled=false (Fase 1): o provider Teams degrada p/ no-op (reunião
    // criada SEM link). Fase 2 = ligar MEETINGS_TEAMS_ENABLED + MS_CALENDAR_SCOPES com Calendars.ReadWrite
    // + cada organizador reconectar a conta Microsoft (OAuth delegado do Meu Dia).
    'meetings' => [
        'teams_enabled'  => filter_var(env('MEETINGS_TEAMS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'organizer'      => env('MEETINGS_ORGANIZER'),
        'organizer_mode' => env('MEETINGS_ORGANIZER_MODE', 'actor'),
    ],

    'github_source' => [
        'api'     => env('GITHUB_SOURCE_API', 'https://api.github.com'),
        'timeout' => (int) env('GITHUB_SOURCE_TIMEOUT', 20),
        // GitHub App (oficial):
        'app_id'                 => env('GITHUB_APP_ID'),
        // Private key da App — informe UMA das duas. Base64 (linha única) é o recomendado no Render;
        // o PEM cru multilinha também é aceito. Só server-side; nunca em banco/log/frontend.
        'app_private_key_base64' => env('GITHUB_APP_PRIVATE_KEY_BASE64'),
        'app_private_key'        => env('GITHUB_APP_PRIVATE_KEY'),
        // Provisionamento automático de repositório por cliente (ESCRITA — exige a App com
        // "Administration: Read and write"). Owner padrão = organização dos repositórios de cliente.
        'default_owner'   => env('GITHUB_SOURCE_DEFAULT_OWNER', 'erpserv-clientes'),
        'auto_provision'  => filter_var(env('GITHUB_SOURCE_AUTO_PROVISION', true), FILTER_VALIDATE_BOOLEAN),
        // LEGADO (provider PAT NÃO-oficial, não bindado, sem fallback):
        'token'   => env('GITHUB_SOURCE_TOKEN'),
    ],

    // Central de Fontes — serviço externo de Análise de Qualidade (CodeAnalysis).
    // Em PROD roda como container 'codeanalysis' no VPS (rede interna, http://codeanalysis:8080).
    // Gate = CODEANALYSIS_ENABLED; vazio/false => aba Qualidade mostra "indisponível".
    'codeanalysis' => [
        'base_url' => env('CODEANALYSIS_BASE_URL', 'http://localhost:8080'),
        'token'    => env('CODEANALYSIS_SERVICE_TOKEN'),
        'timeout'  => (int) env('CODEANALYSIS_TIMEOUT', 120),
        'enabled'  => filter_var(env('CODEANALYSIS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],


    'source_doc_ai' => [
        'provider'   => env('SOURCE_DOC_AI_PROVIDER', 'anthropic'),
        'model'      => env('SOURCE_DOC_AI_MODEL', env('ANTHROPIC_MODEL', 'claude-sonnet-5')),
        'max_tokens' => (int) env('SOURCE_DOC_AI_MAX_TOKENS', 4096),
        'max_chars'  => (int) env('SOURCE_DOC_AI_MAX_CHARS', 40000),
        'timeout'    => (int) env('SOURCE_DOC_AI_TIMEOUT', 120),
        // Bloco 4 — GATE HOMOLOG-ONLY (não depende de disciplina). Envio de código de cliente à IA
        // exige ENABLED=true E ambiente autorizado. Prod (VPS) não define esses vars ⇒ BLOQUEADO.
        // Homolog (Render) roda APP_ENV=production, por isso o ambiente é um marcador PRÓPRIO.
        'enabled'     => filter_var(env('SOURCE_DOC_AI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'environment' => env('SOURCE_DOC_AI_ENVIRONMENT', env('APP_ENV', 'production')),
        'allowed_environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('SOURCE_DOC_AI_ALLOWED_ENVIRONMENTS', 'homolog'))))),
        // Observabilidade de custo (USD por 1M tokens) — só estimativa/telemetria.
        'cost_input_per_mtok'  => (float) env('SOURCE_DOC_AI_COST_IN', 3.0),
        'cost_output_per_mtok' => (float) env('SOURCE_DOC_AI_COST_OUT', 15.0),
        // Bloco 4.1 — orçamento/limite de custo (arquitetura A+C). Não hardcodar em vários lugares.
        'max_relevant_functions'     => (int) env('SOURCE_DOC_AI_MAX_RELEVANT_FUNCTIONS', 12),
        'max_calls'                  => (int) env('SOURCE_DOC_AI_MAX_CALLS', 3),
        'max_input_tokens_per_call'  => (int) env('SOURCE_DOC_AI_MAX_INPUT_TOKENS_PER_CALL', 60000),
        // Output: a chamada GLOBAL (narrativa: objetivo+fluxo+regras+IO+pontos) e o aprofundamento
        // (finalidades) precisam caber sem truncar. Ajustados APÓS medição (o ganho principal foi o
        // split narrativa×funções; estes são o ajuste fino permitido, mantendo custo ≤ US$ 0,25).
        'max_output_tokens_per_call' => (int) env('SOURCE_DOC_AI_MAX_OUTPUT_TOKENS_PER_CALL', 3000),
        'max_output_tokens_global'   => (int) env('SOURCE_DOC_AI_MAX_OUTPUT_TOKENS_GLOBAL', 5000),
        // P1 — output por bloco (centralizado) calibrado pelo output BRUTO medido (não o finalizado):
        // máx observado nos grandes ent 2408 / regras 2649 / deps 2877 (nenhum truncou a 4000). Caps ≥ máx
        // com folga p/ NÃO truncar previsivelmente. O bruto é ~2× (deps ~4×) o finalizado — logo o corte
        // possível é pequeno (ent 4000→2600). O ganho de orçamento vem do #4 (deepening) + guarda P0, não
        // de espremer estes blocos: espremê-los só troca desperdício por truncamento→retry.
        'max_output_tokens_entendimento' => (int) env('SOURCE_DOC_AI_MAX_OUTPUT_TOKENS_ENTENDIMENTO', 2600),
        'max_output_tokens_regras'       => (int) env('SOURCE_DOC_AI_MAX_OUTPUT_TOKENS_REGRAS', 2800),
        'max_output_tokens_deprisco'     => (int) env('SOURCE_DOC_AI_MAX_OUTPUT_TOKENS_DEPRISCO', 3000),
        // Aprofundamento (código das funções críticas): nº de funções e orçamento de tokens do código.
        'max_deepen_functions'       => (int) env('SOURCE_DOC_AI_MAX_DEEPEN_FUNCTIONS', 6),
        'deepen_code_budget_tokens'  => (int) env('SOURCE_DOC_AI_DEEPEN_CODE_BUDGET', 20000),
        'hard_limit_usd'             => (float) env('SOURCE_DOC_AI_HARD_LIMIT_USD', 0.30),
        // Cross-source Fase 1 — resolução determinística (read-only). Limites do 1º gate (depth 1, max 3).
        // Relevância/blocklist NÃO cristalizadas: configuráveis, auditáveis, reversíveis (todo descarte
        // registra symbol/target/reason/relevance_score). Nada aqui envia contexto à IA nesta fase.
        'context_resolver' => [
            'enabled'             => filter_var(env('SOURCE_DOC_CTX_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'depth'              => (int) env('SOURCE_DOC_CTX_DEPTH', 1),
            'max_context_sources' => (int) env('SOURCE_DOC_CTX_MAX_SOURCES', 3),
            'max_context_tokens' => (int) env('SOURCE_DOC_CTX_MAX_TOKENS', 2500),
            // FACTS-FIRST: facts mínimos do alvo entram SEMPRE (barato); snippet completo só se couber.
            // Resolved nunca é descartado por o snippet inteiro não caber (some a perda artificial ~29%).
            'facts_tokens'       => (int) env('SOURCE_DOC_CTX_FACTS_TOKENS', 120),
            'relevance_min'      => (float) env('SOURCE_DOC_CTX_RELEVANCE_MIN', 0.30),
            // blocklist de utilitários (regex, case-insensitive) — descarta contexto trivial. AJUSTÁVEL:
            // o 1º caso real pode mostrar que um "utilitário" é funcional → basta remover daqui.
            'utility_patterns'   => array_values(array_filter(array_map('trim', explode(',', (string) env('SOURCE_DOC_CTX_UTILITY_PATTERNS',
                'grava?log,^log,isrunning,^get.*cod$,^u?_?fmt,format,^str,conout,sleep,^is[A-Z]'))))),
            'chars_per_line'     => (int) env('SOURCE_DOC_CTX_CHARS_PER_LINE', 45), // p/ estimar tokens do snippet
        ],
        // Fase 3 — INJEÇÃO cross-source bounded no prompt semântico. DESLIGADO por padrão: com off, o
        // pipeline se comporta EXATAMENTE como hoje (sem resolver, fingerprint neutro, reuse intacto).
        // Só liga a alimentação do contexto externo (resolved-only) quando explicitamente habilitado.
        'cross_source' => [
            'inject_enabled' => filter_var(env('SOURCE_DOC_XSRC_INJECT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            // ambiguous NÃO entra neste gate (superfície de contaminação); só resolved sustenta contexto/evidence C.
            'include_ambiguous' => filter_var(env('SOURCE_DOC_XSRC_INCLUDE_AMBIGUOUS', false), FILTER_VALIDATE_BOOLEAN),
            // teto BOUNDED de tabelas do alvo materializadas nos facts (fallback nunca despeja tudo).
            'max_context_tables' => (int) env('SOURCE_DOC_XSRC_MAX_TABLES', 6),
        ],
        // P0 — GUARDA DE CUSTO INVIOLÁVEL (dentro do array certo!): reserva de CADA chamada =
        // estimativa × fator. Ancorado no gate: actual/estimated por chamada ∈ ~0,59–1,09 (máx 1,09) →
        // 1,25 ≈ máx observado + 15% de margem. 1,9 super-reservava ~2× e barrava deps que caberia.
        'cost_reserve_factor'        => (float) env('SOURCE_DOC_AI_COST_RESERVE_FACTOR', 1.25),
        // C3 — limite p/ o reaper marcar execução de reprocess como órfã (stale_execution).
        'reap_stale_minutes'         => (int) env('SOURCE_DOC_REAP_STALE_MINUTES', 15),
    ],
];
