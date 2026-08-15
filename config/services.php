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
        'api_key'        => env('ANTHROPIC_API_KEY'),
        'model'          => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        'base_url'       => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'ocr_model'      => env('ANTHROPIC_OCR_MODEL', 'claude-haiku-4-5-20251001'),
        'meetings_model' => env('ANTHROPIC_MEETINGS_MODEL', 'claude-sonnet-5'),
    ],

    // Documentação de fonte (v2) — camada semântica. Provider trocável (governança: API comercial
    // server-side, sem Files/Batch/caching persistente; sanitização e payload mínimo no chamador).
    'source_doc' => [
        // Nº máx. de fontes cuja análise SEMÂNTICA roda inline na GMUD (teto de latência).
        // Os demais ficam 'analyzing' (determinística pronta) e concluem via source-doc:reprocess.
        'inline_semantic_max' => (int) env('SOURCE_DOC_INLINE_SEMANTIC_MAX', 3),
        // Bloco 3: TTL do cache da árvore (path→blob_sha) usada pelo SourceDocStatusResolver.
        // 5–15 min; 10 min por padrão. Anti-N+1: 1 resolução da árvore serve todo o repo.
        'status_cache_seconds' => (int) env('SOURCE_DOC_STATUS_CACHE_SECONDS', 600),
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
        // Aprofundamento (código das funções críticas): nº de funções e orçamento de tokens do código.
        'max_deepen_functions'       => (int) env('SOURCE_DOC_AI_MAX_DEEPEN_FUNCTIONS', 6),
        'deepen_code_budget_tokens'  => (int) env('SOURCE_DOC_AI_DEEPEN_CODE_BUDGET', 20000),
        'hard_limit_usd'             => (float) env('SOURCE_DOC_AI_HARD_LIMIT_USD', 0.30),
        'target_small_usd'           => (float) env('SOURCE_DOC_AI_TARGET_SMALL', 0.05),
        'target_medium_usd'          => (float) env('SOURCE_DOC_AI_TARGET_MEDIUM', 0.10),
        'target_large_usd'           => (float) env('SOURCE_DOC_AI_TARGET_LARGE', 0.25),
        // Código só entra na chamada global se for pequeno; acima disso vai facts + aprofundamento.
        'inline_code_max_chars'      => (int) env('SOURCE_DOC_AI_INLINE_CODE_MAX_CHARS', 8000),
        // Estimador conservador de tokens (chars/token). Código AdvPL ~1,42 (medido); texto/JSON ~3,5.
        'chars_per_token_code'       => (float) env('SOURCE_DOC_AI_CPT_CODE', 1.6),
        'chars_per_token_text'       => (float) env('SOURCE_DOC_AI_CPT_TEXT', 3.2),
        // Cache semântico (Cache facade — NÃO é fonte da verdade; semantic_json persistido é).
        'cache_enabled'              => filter_var(env('SOURCE_DOC_AI_CACHE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'cache_ttl'                  => (int) env('SOURCE_DOC_AI_CACHE_TTL', 2592000), // 30d
        'prompt_version'             => (int) env('SOURCE_DOC_AI_PROMPT_VERSION', 2),   // invalida cache ao mudar prompt
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
        'tenant_id'     => env('MAIL_GRAPH_TENANT_ID'),
        'client_id'     => env('MAIL_GRAPH_CLIENT_ID'),
        'client_secret' => env('MAIL_GRAPH_CLIENT_SECRET'),
        // Caixa que envia notificações do sistema (comunicado de reajuste / alerta).
        'mailbox'       => env('MAIL_GRAPH_MAILBOX', env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS'))),
    ],

    // Microsoft Graph — credenciais app-only do leitor/enviador (Central de Notificações + Comunicações).
    'graph_reader' => [
        'tenant_id'     => env('GRAPH_TENANT_ID', env('MAIL_GRAPH_TENANT_ID')),
        'client_id'     => env('GRAPH_CLIENT_ID', env('MAIL_GRAPH_CLIENT_ID')),
        'client_secret' => env('GRAPH_CLIENT_SECRET', env('MAIL_GRAPH_CLIENT_SECRET')),
    ],

    /*
     * Integração Microsoft 365 por USUÁRIO (OAuth2 delegado) — a MESMA credencial da Agenda do
     * "Meu Dia". Central de Reuniões (Teams) cria a reunião como EVENTO no calendário do usuário
     * logado, reusando este token — por isso os scopes incluem escrita de calendário.
     * Por padrão reusa as credenciais do graph_reader (mesmo app dos e-mails). O INTERRUPTOR é o
     * redirect_uri: sem ele, configured()=false e nada aparece.
     */
    'microsoft_calendar' => [
        'tenant_id'     => env('MS_CAL_TENANT_ID', env('GRAPH_READER_TENANT_ID', env('GRAPH_TENANT_ID', 'common'))),
        'client_id'     => env('MS_CAL_CLIENT_ID', env('GRAPH_READER_CLIENT_ID', env('GRAPH_CLIENT_ID'))),
        'client_secret' => env('MS_CAL_CLIENT_SECRET', env('GRAPH_READER_CLIENT_SECRET', env('GRAPH_CLIENT_SECRET'))),
        'redirect_uri'  => env('MS_CAL_REDIRECT_URI'),
        'scopes'        => env('MS_CAL_SCOPES', 'offline_access openid profile email User.Read Calendars.ReadWrite'),
    ],

    // Central de Reuniões — Teams. A reunião é criada como evento (isOnlineMeeting) no calendário do
    // organizador usando a credencial DELEGADA do Meu Dia (microsoft_calendar). Sem app-only / policy.
    'meetings' => [
        'teams_enabled'  => filter_var(env('MEETINGS_TEAMS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'organizer'      => env('MEETINGS_ORGANIZER', env('MAIL_GRAPH_MAILBOX', env('GRAPH_MAILBOX'))),
        'organizer_mode' => env('MEETINGS_ORGANIZER_MODE', 'service'),
    ],

    /*
     * Clicksign (assinatura eletrônica — API v3 Envelopes). DORMENTE até preencher o token:
     * ClicksignService::enabled() retorna false e nada é enviado.
     * 'env' = sandbox|production define a base URL.
     */
    'clicksign' => [
        'token'          => env('CLICKSIGN_TOKEN'),
        'webhook_secret' => env('CLICKSIGN_WEBHOOK_SECRET'),
        'env'            => env('CLICKSIGN_ENV', 'sandbox'),
    ],

    // Cofre de Senhas — driver do 2º fator: 'microsoft' (Entra, popup a cada unlock)
    // ou 'totp' (app autenticador; fallback/dev). Sem env: microsoft quando o app
    // Entra estiver configurado (MS_CAL_REDIRECT_URI setado), senão totp.
    'vault' => [
        'second_factor' => env('VAULT_2FA_DRIVER'),
    ],

    // Integração de código-fonte (GitHub) — "Solicitação de código-fonte" no Help Desk.
    // READ-ONLY. Provider OFICIAL = GitHub App (Contents: Read-only), autenticação por
    // installation token resolvido/renovado no backend. Fail-safe: sem App configurada,
    // a integração reporta "não configurada" (503) e NUNCA usa outro token.
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

];
