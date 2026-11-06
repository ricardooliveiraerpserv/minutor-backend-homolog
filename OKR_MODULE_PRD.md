# PRD — Módulo Gestão de OKRs (Minutor)

> Planejamento estratégico anual da ERPSERV dentro do Minutor — objetivos corporativos e por área, resultados-chave mensuráveis e acompanhamento mensal. Documento técnico-produto + status de implementação.
>
> **As-of:** 2026-11-06 · **Ambiente:** dev local (`~/PROJETOS/Minutor-desenvolvimento`) · **Status:** 4 fases implementadas, 7 telas, 22 testes verdes — **não commitado/deployado**.
> Documento espelho (claude.ai): `https://claude.ai/artifact/2fvQPvQwY5tGq2ny7vEJna`

---

## 1. Visão Geral e Objetivo

Faz o Minutor deixar de apenas controlar a operação e passar a acompanhar a execução da estratégia da ERPSERV: planejamento anual, objetivos corporativos e por área, KRs mensuráveis e acompanhamento mensal das metas. Módulo **novo e integrado** — reutiliza auth, usuários, perfis, multi-empresa, auditoria e design system. Não cria login/permissões/interface paralelos.

Perguntas que responde: objetivos do ano; áreas responsáveis; resultados a alcançar; meta de cada mês; quanto já realizamos; se estamos dentro do planejado; o que está em risco; que ações tomar; contribuição de cada área; evolução entre anos.

## 2. Diagnóstico da Arquitetura & Reuso

Stack: **BE Laravel 12 / PHP 8.2+ / PostgreSQL**; **FE Next.js 16 (App Router, React 19, Tailwind v4, shadcn)**. API sob `/api/v1`, token Sanctum por aba.

| Subsistema | Reuso | Referência |
|---|---|---|
| Autenticação | `auth:sanctum` + `company.context` | `routes/api.php:129`, `app-layout.tsx` |
| Permissões | arrays PHP `recurso.acao` + `permission.or.admin:` + `ScreenActionAccess` | `app/Services/PermissionService.php` |
| Multi-empresa | `company_id` + trait `BelongsToCompany` (inerte até flag ligar) | `app/Models/Concerns/BelongsToCompany.php` |
| Auditoria | Observers + `*_change_logs` | `app/Observers/ProjectObserver.php` |
| Fechamento | padrão `project_open_periods` (melhorado) | migrations `*_project_open_periods` |
| Cálculo | serviço (preview + snapshot), `round(2)` | `app/Services/HourBankService.php` |
| Design system | tokens CSS + `.ds-*`, recharts | `src/app/globals.css` |
| Navegação | `nav_screens`/`nav_modules` + `nav-catalog.ts` | `NavConfigController` |

Lacunas: não havia tabela de área (criada `okr_areas`); não há CRM formal de leads (proxy `ContractRequest`→`Contract`), nem EVM por atividade.

## 3. Escopo

**v1 (implementado):** planejamento por ano (+copiar), áreas cadastráveis, objetivos corporativos/área + desdobramento, KRs (unidade/direção/agregação), metas mensais auto/manual, acompanhamento mensal + check-in, cálculo de atingimento/aderência/semáforo, fechamento por área + reabertura, dashboard + comparativo entre anos, relatórios/export, permissões e auditoria, modelo pronto p/ fontes automáticas.
**Fora v1:** integrações automáticas efetivas (só o framework), cadência semanal, OKR individual, app mobile nativo.

## 4. Estrutura Organizacional

```
EMPRESA (company_id)
└── ÁREA (okr_areas)
    └── OBJETIVO (okr_objectives, level=area)
        └── RESULTADO-CHAVE (okr_key_results)
            ├── META MENSAL (okr_monthly_targets)
            └── REALIZAÇÃO MENSAL (okr_monthly_results)

OBJETIVO CORPORATIVO (okr_objectives, level=corporativo)
└── vinculado por parent_objective_id aos objetivos de área (desdobramento)
```

Progresso corporativo **nunca soma automaticamente** áreas diferentes — ponderação explícita (`weight`). 7 áreas iniciais via seeder idempotente, mas cadastráveis.

## 5. Modelagem de Dados

Todas: `id`, `company_id` (FK, nullOnDelete) + `BelongsToCompany`, `timestamps`; `softDeletes` nas de negócio. Chave temporal `year_month` string `'2027-01'`. Decimais `decimal(16,4)`, soma como string, `round(2)`.

| Tabela | Campos principais | Chaves/regras |
|---|---|---|
| **okr_cycles** | `year`, `name`, `description`, `responsavel_id`, `start/end_date`, `status`, `settings` (json: semáforo), `approved_by/at`, `closed_by/at` | unique `(company_id, year)` |
| **okr_areas** (+ pivot **okr_area_user**) | `name`, `description`, `responsavel_id` (líder), `active` | softDeletes |
| **okr_objectives** | `okr_cycle_id`, `level`, `okr_area_id`, `parent_objective_id`, `title`, `responsavel_id`, `priority`, `status`, `weight` | FK self (desdobramento) |
| **okr_key_results** | `okr_objective_id`, `name`, `unit`, `direction` (aumentar/reduzir/manter_faixa), `aggregation` (acumulativo/media/posicao_fechamento), `baseline_value`, `target_value`, `band_min/max`, `weight`, `distribution_mode`, `data_source_type` (manual/automatico), `data_source_key`, `status` | softDeletes |
| **okr_monthly_targets** | `okr_key_result_id`, `year_month`, `target_value` (nullable) | unique `(kr, year_month)` |
| **okr_monthly_results** | `okr_key_result_id`, `year_month`, `actual_value` (**NULLABLE**=pendente≠0), `comment`, `risks`, `action_plan`, `situation`, `source`, `reported_by/at` | unique `(kr, year_month)` |
| **okr_period_closures** | `okr_cycle_id`, `okr_area_id` (null=global), `year_month`, `status`, `closed_by/at`, `reopened_by/at`, `reopen_reason` | unique `(cycle, area, year_month)` |
| **okr_result_change_logs** | `okr_key_result_id`, `year_month`, `field_name`, `old_value`, `new_value`, `changed_by`, `created_at` | append-only (Observer) |

## 6. Regras de Cálculo dos KRs

Separa **ATINGIMENTO** (progresso numérico) de **ADERÊNCIA** (realizado acumulado ÷ planejado acumulado até o mês M). Semáforo usa aderência.

- **Aumentar:** `(atual − baseline) / (target − baseline) × 100`
- **Reduzir:** `(baseline − atual) / (baseline − target) × 100`
- **Manter em faixa:** % de meses dentro de `[band_min, band_max]`
- **Aderência(M):** `progresso(realizado acum.) / progresso(planejado acum.) × 100`

Agregação: acumulativo soma; média não soma (anual = média dos informados); posição de fechamento usa o último informado. Semáforo (limiares em `okr_cycles.settings`, default 🟢≥95 🟡≥80 🔴<80 ⚪ sem dado). Consolidação: objetivo = média ponderada dos KRs; área = dos objetivos; corporativo = ponderação explícita dos objetivos de área vinculados. Casos extremos: meta 0, negativos, meta já atingida, pendente≠0, acima da meta, KR iniciado no meio do ano, mudança de meta (preserva histórico).

## 7. Fluxos

- **Planejamento:** criar ciclo (rascunho) → áreas/objetivos corporativos → objetivos de área → KRs → distribuir metas (auto/manual; acumulativo valida soma=anual) → aprovado → em execução; copiar de ano anterior (sem realizações).
- **Acompanhamento:** responsável informa realizado + check-in (planilha 12 meses) → cálculo em tempo real; pendente nunca = 0 → gestor revisa.
- **Fechamento (por área):** gestor fecha o mês (bloqueia edição) → diretoria consolida → reabertura exige `okr.reopen` + justificativa (preserva quem/quando fechou).

## 8. Telas & UX

7 telas sob `/gestao-estrategica/*` (ver seção 17), padrão visual do Minutor (tokens + `.ds-*`, temas claro/escuro). Componentes reutilizados: KpiCard, tabelas, modais, recharts/SVG, barras de progresso, StatusBadge, MonthYearPicker, export CSV.

## 9. Permissões & Auditoria

Permissões `okr.view|create|update|delete|manage|checkin|approve|close|reopen` em `PermissionService` (admin `*`; coordenador=gestor; administrativo; consultor=view+checkin). Enforcement: middleware `permission.or.admin:` nas rotas + checagem por registro nos controllers. Auditoria: `OkrMonthlyResultObserver` → `okr_result_change_logs`; fechamento guarda quem/quando fechou/reabriu.

## 10. Integrações Futuras (Fontes Automáticas)

v1 manual; `data_source_type`/`data_source_key` prontos. Framework `OkrAutomationService` (resolvers guardados por `Schema::hasColumn`): horas apontadas (timesheets), novos contratos / receita contratada (contracts), clientes ativos (customers), receita faturada (fechamento_clientes). Sync em `okr_monthly_results` (source=automatico), pula meses fechados.

## 11. Roadmap por Fases — todas ✅ (dev local)

| Fase | Entregue |
|---|---|
| 1 — Fundação | Ciclos, áreas, objetivos, KRs, metas mensais, permissões, `OkrProgressService` |
| 2 — Acompanhamento | Realizações, check-ins, histórico (Observer), `monthlySeries`, telas monthly/monitoring |
| 3 — Gestão | `OkrConsolidationService`, dashboard, fechamento/reabertura, **registro no Configurador** |
| 4 — Automações | `OkrAutomationService` (catálogo + resolvers), endpoints sources/sync |

## 12. Riscos Técnicos

null vs zero (actual nullable) · somar %/taxas (campo aggregation + testes) · decimal (string + round 2) · Next 16 breaking changes (ler docs locais) · navegação (nav_screens/modules + catalog) · multi-empresa (company_id desde já) · fechamento preserva reabertura · suite feature não roda em sqlite (migration CONCURRENTLY pg-only, alheia ao OKR → usar pgsql `minutor_test`).

## 13. Critérios de Aceite & Testes

**22 testes verdes** (99 asserções): `tests/Unit/OkrProgressServiceTest` (fórmulas, série mensal, distribuição) + `tests/Feature/OkrModuleTest` (criar ciclo/área/objetivo/KR, distribuir auto/manual, copiar planejamento, check-in → progresso, histórico, consolidação por área, trava de mês fechado + reabertura, catálogo e sync automático, permissões). Rodar: `DB_CONNECTION=pgsql DB_DATABASE=minutor_test php artisan test --filter=Okr`.

## 14. Decisões

`okr_areas` nova (com responsável + company_id); fechamento por área; atingimento × aderência separados; fontes automáticas como framework seguro. Em aberto: campo `confidence` no check-in; snapshot de meta ao alterar no ano; limiares do semáforo por área.

## 15. Status de Implementação (2026-11-06, dev local)

Backend: **11 migrations aplicadas** (9 tabelas `okr_*` + 2 de navegação), **31 rotas** `/api/v1/okr/*`, 22 testes verdes. Frontend: 7 telas, `tsc` limpo. Nada commitado/promovido.

## 16. Navegação no Configurador de Menus

| Camada | Onde | Registrado |
|---|---|---|
| `nav_screens` (DB) | backend | 7 telas `/gestao-estrategica/*`, `active=true`, perfis admin/administrativo/coordenador_*/consultor_* |
| `nav_modules` (DB) | backend | grupo `g_okr` "Gestão Estratégica" nos módulos `administrativo`, `administrativo__administrativo`, `servicos__coordenador_projetos`, `servicos__coordenador_sustentacao` |
| `nav-catalog.ts` (FE) | frontend | 7 telas no grupo "Gestão Estratégica" |

Via migrations `2026_11_06_130000_register_okr_nav` + `2026_11_06_140000_register_okr_nav_reports_config` (idempotentes, com `down()`).

**⚠️ Escopo (por que pode não aparecer):** aplicado **somente em dev local** (base `minutor` + código não commitado). Ambientes deployados (dev Vercel/Render `minutor_dev`, homolog, produção) só exibem o menu após **promover o código** e **rodar as migrations** naquela base. No local, o `next dev` precisa recompilar para o Configurador listar as telas. Enquanto não há promoção, as telas abrem por URL direta (`/gestao-estrategica/...`).

## 17. Checklist de Telas — 100% implementado

| # | Tela | Rota | Arquivo FE | Status |
|---|---|---|---|---|
| 1 | Dashboard | `/gestao-estrategica/dashboard` | `src/app/gestao-estrategica/dashboard/page.tsx` | ✅ |
| 2 | Planejamento Anual | `/gestao-estrategica/planejamento` | `.../planejamento/page.tsx` | ✅ |
| 3 | Objetivos | `/gestao-estrategica/objetivos` | `.../objetivos/page.tsx` | ✅ |
| 4 | Resultados-chave | `/gestao-estrategica/krs` | `.../krs/page.tsx` | ✅ |
| 5 | Acompanhamento Mensal | `/gestao-estrategica/acompanhamento` | `.../acompanhamento/page.tsx` | ✅ |
| 6 | Relatórios | `/gestao-estrategica/relatorios` | `.../relatorios/page.tsx` | ✅ |
| 7 | Configurações | `/gestao-estrategica/configuracoes` | `.../configuracoes/page.tsx` | ✅ |

Sub-navegação compartilhada: `src/components/gestao-estrategica/okr-nav.tsx` (7 abas).

---

### Arquivos do módulo (backend)

- Migrations: `database/migrations/2026_11_06_1000*_create_okr_*`, `..._1100*`, `..._120000_create_okr_period_closures`, `..._130000_register_okr_nav`, `..._140000_register_okr_nav_reports_config`
- Models: `app/Models/Okr{Cycle,Area,Objective,KeyResult,MonthlyTarget,MonthlyResult,PeriodClosure,ResultChangeLog}.php`
- Services: `app/Services/Okr{Progress,Consolidation,Automation}Service.php`
- Controllers: `app/Http/Controllers/Okr{Cycle,Area,Objective,KeyResult,Monitoring,Dashboard,Closure,Automation}Controller.php`
- Observer: `app/Observers/OkrMonthlyResultObserver.php` (registrado em `AppServiceProvider`)
- Permissões: `app/Services/PermissionService.php` (`okr.*`)
- Seeder: `database/seeders/OkrAreaSeeder.php`
- Rotas: `routes/api.php` (grupo `prefix('okr')`)
- Testes: `tests/Unit/OkrProgressServiceTest.php`, `tests/Feature/OkrModuleTest.php`
