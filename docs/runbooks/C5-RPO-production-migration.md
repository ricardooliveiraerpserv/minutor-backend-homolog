# Runbook — Rollout de PRODUÇÃO das migrations de governança de RPO (Conector C5)

> **Escopo:** ciclo RPO **hot** (C5.1 registry → C5.2 promote → C5.3 rollback → C5.4 hardening).
> **Regra dura:** PRODUÇÃO **NÃO** pode depender de `migrate` automático no boot para estas migrations.
> Execução **explícita, verificável e reversível-ou-forward-fix**. Executável por qualquer operador sem o autor.
> Nenhum deploy em produção está autorizado por este documento — ele descreve **como** fazer com segurança quando autorizado.

---

## 0. Migrations de governança C5 (ordem OBRIGATÓRIA)

| # | Arquivo | Fase | O que cria/altera | Depende de |
|---|---|---|---|---|
| 1 | `2026_08_30_100000_create_connector_inventory_tables.php` | C-2 | `connector_environment_state` (+`observed_json`,`rpo_capability`), `connector_rpo_snapshots`, `connector_events` | tabelas base do Conector (C-0/C-1) |
| 2 | `2026_09_05_100000_create_connector_operations_table.php` | C4 | `connector_operations` + **índices únicos parciais** `one_live_per_appserver` / `one_live_per_environment` | #1 |
| 3 | `2026_09_10_100000_create_rpo_registry_tables.php` | C5.1 | `rpo_artifacts`, `rpo_targets`, `rpo_target_appservers` (UNIQUE env+ref), `rpo_qualifications` + coluna `rpo_capability` | #1 |
| 4 | `2026_09_15_100000_c52_rpo_promote_columns.php` | C5.2 | `connector_operations.appserver_ref` **DROP NOT NULL**, `+effect_started_at`, `+rpo_target_id`; `rpo_targets.+last_successfully_published` | #2, #3 |
| 5 | `2026_09_20_100000_c54_resolution_record.php` | C5.4 | `connector_operations.+resolution` (jsonb) | #2 |

Todas são **idempotentes** (`hasTable`/`hasColumn` guards) — reexecução não duplica. Ainda assim, **execute uma vez, na ordem, com verificação**.

---

## 1. PRE-CHECK (abortar se qualquer item falhar)

```bash
# variáveis do ambiente-alvo (NUNCA rodar apontado p/ base errada)
export TARGET_DB=...            # confirmar host/porta/nome
php artisan tinker --execute="echo config('database.connections.pgsql.database');"   # DEVE ser o banco esperado
```

- [ ] **Versão/commit** do backend implantado = commit esperado (`git rev-parse HEAD` no servidor == commit aprovado no gate).
- [ ] **Banco correto** confirmado (nome/host/porta) — comparar com inventário de infra.
- [ ] **Backup / restore point** criado AGORA (`pg_dump` lógico + snapshot de volume) e **verificado restaurável**.
- [ ] **Estado das migrations**: `php artisan migrate:status` — as 5 acima aparecem como **Pending** (ou já aplicadas, e então nada a fazer).
- [ ] **Sem operação destrutiva viva** em `connector_operations`:
  ```sql
  SELECT id, op_type, status FROM connector_operations
   WHERE status NOT IN ('failed','expired','canceled','rejected','reconciled_success','reconciled_noop');
  ```
  DEVE retornar **0 linhas**. Se houver, **PARAR** — drene/resolva antes (ver runbook de recovery).
- [ ] **Agentes/Conectores compatíveis**: AGENT-V1; capability declarada `rpo_publish` com `contract_version` na allowlist (`config('connector.operations.rpo.supported_capabilities')`) e `activation_mode=hot`.
- [ ] **Env vars** de governança presentes e nos valores de produção:
  - `CONNECTOR_RPO_APPROVALS_PROD=2` (N-of-M em prod)
  - `CONNECTOR_OP_RPO_DEADLINE=180`, `CONNECTOR_OP_RPO_RECONCILE_WINDOW=300`
  - `CONNECTOR_RPO_EXEC_ACTIVATION_MODES=hot` (SÓ hot)
- [ ] **Permissões**: perfis com `prosight.operations.rpo.manage|qualify|promote|rollback|approve` e `prosight.operations.view` conforme política.
- [ ] **Targets/qualifications**: se a base já tem dados C5, inventariar `rpo_targets`/`rpo_qualifications` (para POST-CHECK comparativo).
- [ ] **Sem inconsistência de schema**: `php artisan migrate:status` sem "ran but missing file"; nenhuma coluna C5 pré-existente com tipo divergente.

---

## 2. EXECUÇÃO (explícita, sem auto-migrate)

> **Não** habilitar `migrate` no entrypoint/boot. Rodar manualmente, uma migration por vez, verificando cada uma.

```bash
# janela de manutenção recomendada (a publicação hot não exige, mas o rollout de schema sim).
cd /opt/minutor/backend

# opção A — todas as pendentes de uma vez (idempotentes, ordem garantida pelo timestamp):
php artisan migrate --force

# opção B — CIRÚRGICA, uma a uma (preferida em produção sensível):
php artisan migrate --force --path=database/migrations/2026_08_30_100000_create_connector_inventory_tables.php
php artisan migrate --force --path=database/migrations/2026_09_05_100000_create_connector_operations_table.php
php artisan migrate --force --path=database/migrations/2026_09_10_100000_create_rpo_registry_tables.php
php artisan migrate --force --path=database/migrations/2026_09_15_100000_c52_rpo_promote_columns.php
php artisan migrate --force --path=database/migrations/2026_09_20_100000_c54_resolution_record.php
```

**Expected result:** cada comando imprime `... DONE`. `CREATE INDEX CONCURRENTLY` (se houver) exige `$withinTransaction=false` — já tratado nas migrations do Conector; se aparecer erro de transação, **não** improvisar, revisar a migration específica.

Após migrar: `php artisan config:cache` (para as env vars entrarem no config cacheado) e reiniciar workers/opcache conforme o padrão de deploy.

---

## 3. POST-CHECK (todas DEVEM passar)

- [ ] **Migrations aplicadas**: `php artisan migrate:status` — as 5 como **Ran**.
- [ ] **Tabelas/colunas/constraints**:
  ```sql
  SELECT to_regclass('rpo_artifacts'), to_regclass('rpo_targets'),
         to_regclass('rpo_target_appservers'), to_regclass('rpo_qualifications');   -- todas não-nulas
  SELECT is_nullable FROM information_schema.columns
   WHERE table_name='connector_operations' AND column_name='appserver_ref';          -- 'YES'
  SELECT column_name FROM information_schema.columns
   WHERE table_name='connector_operations' AND column_name IN ('effect_started_at','rpo_target_id','resolution');  -- 3 linhas
  SELECT column_name FROM information_schema.columns
   WHERE table_name='rpo_targets' AND column_name='last_successfully_published';      -- 1 linha
  ```
- [ ] **Índices** de concorrência presentes:
  ```sql
  SELECT indexname FROM pg_indexes WHERE tablename='connector_operations'
   AND indexname IN ('connector_operations_one_live_per_appserver','connector_operations_one_live_per_environment');  -- 2 linhas
  SELECT indexname FROM pg_indexes WHERE tablename='rpo_target_appservers';           -- UNIQUE env+ref presente
  ```
- [ ] **Permissions**: `PermissionService` expõe as chaves C5 (smoke via rota autenticada 200/403 conforme perfil).
- [ ] **API viva**: `GET /api/v1/prosight/environments/{env}/rpo/capability` → 200 (perfil com view); `.../rpo/targets`, `.../rpo/artifacts` → 200.
- [ ] **Connector compatibility**: um inventário assinado é aceito (200) e `rpo_capability` é persistido; capability aparece `available=true` se `contract_version` na allowlist.
- [ ] **Registry/targets/qualification/preview**: `preview` (promote) e `rollback-preview` respondem read-only **sem** criar operação:
  ```sql
  SELECT count(*) FROM connector_operations WHERE op_type IN ('rpo_promote','rpo_rollback');  -- inalterado após previews
  ```
- [ ] **Nenhuma operação criada indevidamente** pelo rollout (contagem de `connector_operations` == pré-rollout).

---

## 4. ROLLBACK de DEPLOY / SCHEMA — o que é reversível

Separe explicitamente as quatro camadas:

### 4.1 Rollback de APLICAÇÃO (código)
- Reversível: **sim**. Reimplantar o commit anterior. O código C5 é aditivo; a versão anterior simplesmente não expõe os endpoints C5. **Não** apaga dados.

### 4.2 Rollback de CONFIGURAÇÃO (env vars)
- Reversível: **sim**. Restaurar env vars anteriores + `config:cache`. (Ex.: desabilitar promote reduzindo permissões/allowlist de activation modes para vazia — bloqueia criação sem tocar schema.)

### 4.3 Rollback de MIGRATION (schema)
- **Reversível com segurança SOMENTE enquanto não existir dado C5** (nenhum `rpo_artifacts`/`rpo_targets`/`rpo_qualifications`, nenhuma `connector_operations` rpo). Nesse caso `php artisan migrate:rollback --step=N --force` recria o estado anterior.
- **Depois que dados C5 existem:** ver §4.4. **NÃO** rode `down()` que dropa tabelas/colunas com dados — é **perda de auditoria irreversível**.

### 4.4 FORWARD-FIX REQUIRED (não fingir rollback destrutivo)
As seguintes reversões são **inseguras após dados existirem** e devem ser tratadas por **forward-fix**, não por `down()`:

| Migration | Reversão perigosa | Forward-fix |
|---|---|---|
| `create_rpo_registry_tables` | dropar `rpo_*` → perde artefatos/targets/known_good/histórico | manter tabelas; desabilitar uso por permissão/config; corrigir com nova migration aditiva |
| `c52_rpo_promote_columns` | re-`SET NOT NULL` em `appserver_ref` **falha** se houver op rpo (appserver_ref null); dropar `effect_started_at`/`rpo_target_id`/`last_successfully_published` → perde evidência | manter colunas; nova migration aditiva se precisar corrigir |
| `c54_resolution_record` | dropar `resolution` → perde registro de resolução humana (evidência) | manter coluna; corrigir via nova migration |

**Princípio:** RPO é governança auditável. Reversão que apaga evidência é proibida; use **forward-fix** (nova migration aditiva) e documente.

---

## 5. Assinaturas

- Executor: __________  Data/hora: __________  Commit: __________
- Verificador (POST-CHECK): __________  Resultado: PASS / FAIL
- Backup/restore point: __________ (verificado restaurável: sim/não)
