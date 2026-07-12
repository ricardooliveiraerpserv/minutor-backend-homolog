# PRD — BOT Minutor: IA Operacional com Permissões e Detectores

> **Versão:** v1.0
> **Data:** 2026-06-26
> **Branch:** `dev2` (backend `e6d7a36`, frontend `8e1c60e`)
> **Status:** Pronto para produção (após `php artisan migrate --force`)

---

## 1. Sumário executivo

O BOT Minutor é o assistente operacional de IA da ERPSERV, invocado no chat interno via `@bot`.
Ele consulta dados reais do sistema (clientes, contratos, financeiro, banco de horas,
Movidesk, despesas) usando 22 **tools** específicas e responde de forma estruturada em
markdown. Esta versão entrega o ciclo completo de **observabilidade proativa** (detectores
que varrem o sistema 1× ao dia e geram alertas) e **permissões granulares** (cada perfil
de usuário vê apenas os dados que o admin liberar — incluindo "self" para que consultor
não veja folha de outro consultor).

### Por que existe

- Operação consultiva (financeiro, fechamento, suporte) gera dezenas de cliques diários
  pra responder perguntas que o dado já tem ("qual o saldo do consultor X?", "tem despesa
  vencendo?").
- Sem proatividade, riscos só aparecem quando viram problema (cliente reclama, banco de
  horas estoura, ticket vence SLA).
- Visibilidade era binária (ou todo mundo via tudo, ou desligava o módulo).

### O que entrega

1. **BOT IA conversacional** com 22 tools cobrindo customer/project/contract/financial/
   billing/payroll/bankhours/approvals/overview/support.
2. **Detectores proativos** customizáveis pelo admin que rodam diariamente e criam
   alertas no Operational Feed.
3. **Permissões em 3 camadas** (porteiro × áreas × visibilidade self/team/all) com
   política padrão por perfil editável em `/configuracoes/bot-minutor → Permissões`.
4. **Erros amigáveis**: SQL/exception nunca vazam no chat — usuário recebe pedido pra
   especificar melhor.

---

## 2. Personas e cenários

| Persona | Como usa |
|---|---|
| **Admin / Diretoria** | "@bot fechamento de junho", "@bot quem tá com banco de horas crítico" — visão total |
| **Coordenador** | "@bot status dos meus consultores", "@bot apontamentos atrasados da minha equipe" — visão team |
| **Consultor** | "@bot meu pagamento de junho", "@bot meu saldo de banco de horas", "@bot tickets abertos meus" — visão self |
| **Cliente externo** | Acesso ao BOT desligado por padrão |
| **Parceiro** | Acesso ao BOT desligado por padrão |

### Cenários de aceitação

- **C1 — Consultor não vê folha do colega:** consultor pede `@bot pagamento do João` → BOT
  retorna "Você só pode consultar dados sobre você mesmo." (em vez de mostrar a folha).
- **C2 — Admin edita política:** admin abre Permissões, desmarca `billing` no card Consultor,
  salva. Consultores existentes mantêm overrides individuais. Novos consultores nascem
  sem billing.
- **C3 — Detector dispara:** sexta às 8h, 3 consultores estouram banco de horas → 3 eventos
  no Operational Feed → routing rules notificam o coordenador via inbox/email.
- **C4 — Admin cria detector customizado:** admin escreve SQL "contratos vencendo em 30
  dias", define título/mensagem, agenda → roda no próximo cron.
- **C5 — BOT erra a query:** se uma tool quebrar (coluna ausente, parâmetro inválido), o
  chat mostra "Não consegui montar essa consulta, pode me dar mais detalhes?" — nunca o
  stack trace.

---

## 3. Arquitetura

```
                 ┌─────────────────────────────────────────────────────┐
   chat (@bot)  ─►   InboxController.send                              │
                 │      └─► BotQueryService                            │
                 │           ├─ computeAllowedScopes (system × user)   │
                 │           ├─ tool-use loop (Anthropic Claude)       │
                 │           └─► MinutorToolRegistry.execute           │
                 │                ├─ BotAccessControl.applyToolFilters │
                 │                │    (self/team/all + overrides)     │
                 │                └─ dispatchTool (22 tools)           │
                 └─────────────────────────────────────────────────────┘

                 ┌─────────────────────────────────────────────────────┐
   cron 08:00   ─►   bot:proactive-alerts                              │
                 │      └─► ProactiveAlertsService.runAll              │
                 │           ├─ itera BotProactiveDetector::active     │
                 │           └─► OperationalFeed.create (dedupe)       │
                 │                └─► NotificationEngine               │
                 │                     └─► inbox / email / grupo       │
                 └─────────────────────────────────────────────────────┘
```

### Componentes-chave (backend)

| Arquivo | Papel |
|---|---|
| `app/Services/Ai/BotQueryService.php` | Loop iterativo IA + persistência da conversa |
| `app/Services/Ai/Tools/MinutorToolRegistry.php` | 22 tools com scope + try/catch global |
| `app/Services/Ai/BotAccessControl.php` | Resolve self/team/all + filtra user_id/customer_id |
| `app/Services/Bot/ProactiveAlertsService.php` | Despacha cada tipo de detector + valida SQL custom |
| `app/Console/Commands/BotProactiveAlertsCommand.php` | Cron — itera detectores do DB |
| `app/Http/Controllers/BotDetectorController.php` | CRUD + test + run + validate-sql |
| `app/Http/Controllers/BotPermissionProfileController.php` | CRUD da política padrão por perfil |
| `app/Models/BotProactiveDetector.php` | id, slug, type, config jsonb, severity, dedupe_window_hours, is_system |
| `app/Models/BotPermissionProfile.php` | id, profile_type, can_use_bot, allowed_scopes, visibility, scope_overrides |
| `app/Models/User.php` (helpers) | botTeamUserIds(), botTeamCustomerIds() — resolução de "team" |

### Componentes-chave (frontend)

| Arquivo | Papel |
|---|---|
| `src/app/configuracoes/bot-minutor/page.tsx` | 8 abas (Geral, Providers, Agents, Skills, Detectores, **Permissões**, Notificações, Grupos) |
| `src/components/bot-config/DetectorsTab.tsx` | Listar/criar/editar/testar/rodar detectores |
| `src/components/bot-config/PermissionsTab.tsx` | 6 cards (1 por perfil), edição inline |
| `src/components/users/BotScopesEditor.tsx` | Áreas que user pode consultar (10 scopes) |
| `src/components/users/BotVisibilityEditor.tsx` | Self/team/all + override por scope |
| `src/components/users/user-form-modal.tsx` | "Resetar para padrão do perfil" + override individual |

---

## 4. Modelos de dados (tabelas novas/alteradas)

### `users` (alteração)
- `can_use_bot` boolean — porteiro
- `bot_allowed_scopes` jsonb — array de scopes ou null (sem restrição)
- `bot_visibility` enum(`self|team|all`) — escopo de entidade
- `bot_scope_overrides` jsonb — `{ "payroll": "self", "billing": "denied", ... }`

### `bot_proactive_detectors` (nova)
- `slug` unique, `name`, `description`, `active`
- `detector_type` (bank_hours_threshold | expense_payment_age | timesheet_pending_age |
  ticket_stale_age | late_timesheets | sql | custom)
- `config` jsonb — varia por tipo (ex.: `{ threshold_hours: 16 }` ou `{ sql, title_template, message_template }`)
- `severity`, `source`, `event_type`, `dedupe_window_hours`
- `is_system` boolean — bloqueia delete dos 5 padrões
- `last_run_at`, `last_run_alerts`, `last_run_error`

### `bot_permission_profiles` (nova)
- `profile_type` unique (admin | administrativo | coordenador | consultor | cliente | parceiro_admin)
- `label`, `description`
- `can_use_bot`, `allowed_scopes`, `visibility`, `scope_overrides`

### `bot_agents` (já existia, alteração anterior)
- `allowed_scopes` jsonb — agents só usam tools desses scopes

---

## 5. Permissões — modelo final

### 3 camadas em ordem
1. **`can_use_bot`** — porteiro liga/desliga BOT pro user
2. **`bot_allowed_scopes`** — quais ÁREAS (financial, payroll, billing, …)
3. **`bot_visibility` + `bot_scope_overrides`** — de QUEM os dados (self/team/all)

### Como "team" é resolvido (helper `User::botTeamUserIds`/`botTeamCustomerIds`)
- Projetos onde o user é: consultor (pivot), coordenador (pivot), architect, executivo_conta,
  vendedor
- Contratos onde o user é kanban_coordinator
- Customer vinculado (campo `users.customer_id` para perfil cliente)

### Composição final
`acesso_final = intersecção(union agents ativos, bot_allowed_scopes do user) ∩ visibility_resolvida(scope)`

### Defaults seedados (editáveis em /configuracoes/bot-minutor → Permissões)
| Perfil | can_use_bot | scopes | visibility |
|---|---|---|---|
| admin | ✅ | todos | all |
| administrativo | ✅ | todos | all |
| coordenador | ✅ | todos | team |
| consultor | ✅ | customer/project/financial/approvals/support | self |
| cliente | ❌ | customer/project/support | self |
| parceiro_admin | ❌ | customer/project/contract | self |

---

## 6. Detectores — modelo

### Tipos suportados
| Tipo | Config | O que detecta |
|---|---|---|
| `bank_hours_threshold` | `{ threshold_hours: 16 }` | Consultores com `|saldo|` ≥ X no mês corrente |
| `expense_payment_age` | `{ days: 7 }` | Despesas aprovadas há > N dias sem pagamento |
| `timesheet_pending_age` | `{ days: 5 }` | Apontamentos pending há > N dias |
| `ticket_stale_age` | `{ days: 3 }` | Tickets Movidesk em aberto sem update há > N dias |
| `late_timesheets` | `{}` | Apontamentos com status `late` |
| `sql` | `{ sql, title_template, message_template, max_rows }` | SELECT custom + templates `{{coluna}}` |
| `custom` | livre | Reservado pra handler externo (não roda hoje) |

### Segurança do tipo `sql`
- Apenas `SELECT` ou `WITH` (regex)
- Sem `;` múltiplo (1 statement por detector)
- Blocklist: `INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|COPY|CALL|DO|COMMIT|ROLLBACK|VACUUM|REINDEX`
- `max_rows` limitado a 500
- Dedupe por hash da linha quando `dedupe_key` não vem na query

### Defaults seedados (`is_system=true`)
- `bank_hours_critical` — saldo |≥16h|
- `expense_payment_overdue` — despesas >7 dias
- `timesheets_pending_long` — apontamentos >5 dias
- `tickets_stale` — tickets >3 dias
- `timesheets_late` — status `late`

### Cron (já no `routes/console.php`)
```php
Schedule::command('bot:proactive-alerts')->dailyAt('08:00')...
```

---

## 7. APIs novas

### BOT Detectores
- `GET    /api/v1/bot/detectors` — lista + tipos/severities/event_types/sources
- `POST   /api/v1/bot/detectors` — cria
- `PUT    /api/v1/bot/detectors/{id}` — atualiza
- `DELETE /api/v1/bot/detectors/{id}` — exclui (proibido em `is_system`)
- `POST   /api/v1/bot/detectors/{id}/test` — dry-run (não cria feed)
- `POST   /api/v1/bot/detectors/{id}/run` — executa agora
- `POST   /api/v1/bot/detectors/run-all` — todos os ativos
- `POST   /api/v1/bot/detectors/validate-sql` — valida SQL custom

### BOT Permissões padrão
- `GET    /api/v1/bot/permission-profiles` — lista 6 perfis
- `PUT    /api/v1/bot/permission-profiles/{type}` — atualiza

### User
- `POST   /api/v1/users` — agora aplica defaults da política do perfil quando campos do BOT vêm vazios

---

## 8. Telemetria / auditoria

- `Log::warning('[BotAccessControl] denied', { user_id, tool, scope, access, reason })`
  para toda tool barrada — facilita ver se um perfil está pedindo mais do que devia.
- `Log::error('[MinutorTool:{name}] DB error', { sql, error, input })` — QueryException
  do tool registry, sem vazar pro chat.
- `Log::error('[ProactiveAlerts] detector {slug}: …')` — falha por detector + `last_run_error`
  visível na UI.

---

## 9. Plano de rollout

### Pré-requisitos
- [x] Branch `dev2` mergeada (backend `e6d7a36`, frontend `8e1c60e`)
- [x] Deploy backend Render `dev2` rodando
- [x] Deploy frontend Vercel `dev2` em production (`8e1c60e`)
- [ ] **Migration `php artisan migrate --force` no Render Shell** ← passo obrigatório

### Sequência de go-live
1. Tag git de backup nos dois repos (ver §11)
2. `php artisan migrate --force` no backend (cria `bot_proactive_detectors`,
   `bot_permission_profiles`, colunas `bot_visibility`/`bot_scope_overrides` em users).
   Migration faz backfill por perfil (admin→all, coord→team, resto→self).
3. Smoke test em produção:
   - `/configuracoes/bot-minutor` carrega as 8 abas
   - Aba Permissões lista 6 cards
   - Aba Detectores lista 5 detectores `is_system`
   - `@bot meu saldo` responde
   - `@bot pagamento do [outro user]` é negado
4. Comunicado interno: admins reveem política do perfil Consultor antes do cron rodar.

### Plano de rollback
- Tag `pre-prod-2026-06-26` ressetada via `git reset --hard <tag>` + redeploy.
- DB: as 3 colunas/2 tabelas novas são aditivas — `down()` das migrations remove
  limpamente sem corromper dados pré-existentes.

---

## 10. Trade-offs e decisões

| Decisão | Por quê | Alternativa rejeitada |
|---|---|---|
| Defaults por perfil em tabela (não enum hardcoded) | Admin precisa editar sem deploy | Hardcoded em código |
| SQL custom em vez de UI 100% guiada | Detector novo sem deploy | Quebrar por tipo + UI por filtro |
| `bot_visibility` separado de `bot_allowed_scopes` | Áreas e entidades são dimensões diferentes ("posso ver folha (área) mas só a minha (entidade)") | Misturar tudo em um array tipo `payroll:self,billing:all` |
| Try/catch global no Registry | LLM não revela schema; UX não quebra | Catch por tool |
| Tools agregadas (`financial_overview`, `critical_bank_hours`) bloqueadas fora de `all` | Não pode delegar nem com override "team" | Permitir override |
| `is_system=true` impede delete dos 5 detectores | Operação dia-a-dia depende deles | Deletar livre |

---

## 11. Backup / restore

### Tags git criadas (referência)
- `backend@dev2`: `pre-prod-2026-06-26` apontando para commit `e6d7a36`
- `frontend@dev2`: `pre-prod-2026-06-26` apontando para commit `8e1c60e`

### Restore de código
```bash
git -C <repo> checkout pre-prod-2026-06-26
git -C <repo> push --force-with-lease origin dev2  # somente se já mergeou prod
```

### Restore de dados (DB)
- Render → minutor-backend-db → Backups → Restore from a snapshot
- Migrations novas são reversíveis: `php artisan migrate:rollback --step=3` derruba
  `bot_permission_profiles`, `bot_visibility` em users, `bot_proactive_detectors`.

---

## 12. KPIs de adoção (próximos 30 dias)

- N de queries `@bot` por dia (objetivo: >50)
- % de queries respondidas sem erro / total
- N de detectores ativos customizados (≠ system)
- N de alertas barrados pelo `BotAccessControl` (sinaliza permissão indevida)
- N de eventos no Operational Feed gerados por detectores vs gerados manualmente

---

## 13. Roadmap futuro (fora deste PRD)

- BOT escreve no sistema (criar apontamento, aprovar despesa via chat) — hoje só lê.
- Voice input → `@bot` pelo áudio do chat.
- RAG sobre documentos contratuais.
- Detectores compostos (AND/OR entre tipos).
- Política por user-ID além de por perfil.
- Métricas de adoção na própria página `/configuracoes/bot-minutor` (telemetria embutida).

---

## 14. Riscos conhecidos

| Risco | Mitigação |
|---|---|
| LLM tenta contornar permissão chamando tool com outro `user_id` | `BotAccessControl::applyToolFilters` valida antes do dispatch, retorna erro de permissão pro LLM, system prompt orienta a recusar |
| Detector SQL custom mal feito quebra o cron | Try/catch por detector + `last_run_error` persistido, segue pro próximo |
| Política de perfil editada errada → consultores perdem acesso | Cada user mantém `bot_*` próprio (override) — política só afeta criação |
| Cron `bot:proactive-alerts` cria spam | Dedupe por `dedupe_key` + `dedupe_window_hours` (default 24h) |
| `_botTeamUserIds()` pesado em DBs grandes | Hoje usa 5 queries diretas — OK até ~10k users. Se virar gargalo, cache em Redis por 5min |

---

## 15. Anexos

- **Commits relevantes (range)**: backend `449f1c0..e6d7a36`, frontend `bef76e8..8e1c60e`
- **Documentos relacionados**:
  - [CLAUDE.md](../CLAUDE.md) — referência do backend
  - [Frontend AGENTS.md](../../Frontend/AGENTS.md) — design system
- **Conta Anthropic**: provider ativo `anthropic`, modelo padrão `claude-sonnet-4-6`,
  configurável em `/configuracoes/bot-minutor → Geral`
