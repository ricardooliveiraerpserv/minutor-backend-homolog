# A2 — Backend Minutor ↔ CodeAnalysis (Análise de Qualidade)

Gate A2 da Fase 1. Integra o backend do Minutor com a API JSON do CodeAnalysis (A1) para a
Central de Fontes. **Não inclui frontend (A3).** Branch: `feat/codeanalysis-integration`
(criada a partir de `gmud-source-publish`, que contém o `source_docs`; sem commits de GMUD).

## Princípio de responsabilidade
- **Minutor (autoridade de negócio):** usuário, cliente, source_doc, versão, permissão, auditoria, escopo.
- **CodeAnalysis (autoridade técnica):** execução, analyzer, findings, score, status do job.
- O Minutor guarda só o **vínculo** (tabela `source_doc_quality_analyses`) — **não copia findings**.

## Arquivos
| Arquivo | Papel |
|---|---|
| `database/migrations/2026_08_23_160000_create_source_doc_quality_analyses_table.php` | tabela de vínculo (híbrida) + índice único parcial anti-duplo-clique |
| `app/Models/SourceDocQualityAnalysis.php` | model do vínculo (status, score resumido, blob/versão) |
| `app/Services/SourceDocQualityService.php` | cliente HTTP server-to-server do A1 (Http facade, sem retry no POST) |
| `app/SourceCode/Exceptions/CodeAnalysisException.php` | erro tipado (unavailable × bad_request) |
| `app/Http/Controllers/SourceDocQualityController.php` | show/run/history: escopo, fetch server-side, concorrência, resiliência, auditoria, estado |
| `config/services.php` | bloco `codeanalysis` (base_url/token/timeout/enabled) |
| `app/Services/PermissionService.php` | +`source_docs.quality.view` / `+quality.run` (coordenador recebe só `.view`) |
| `routes/api.php` | rotas `/source-docs/{sourceDoc}/quality[/history]` |
| `app/Providers/AppServiceProvider.php` | bind do service a partir de config |
| `tests/Feature/SourceDocQualityTest.php` | 18 testes (54 assertions) — os 20 casos exigidos |

## Contrato (Minutor)
Prefixo `/api/v1`. Todas exigem `auth:sanctum` + permissão.

### GET `/source-docs/{sourceDoc}/quality` — permissão `source_docs.quality.view`
Estado da qualidade da **versão vigente**. Faz polling limitado (1 chamada) ao A1 só se houver job em andamento.
```json
{ "data": {
  "state": "never_analyzed|queued|running|completed|failed|outdated",
  "source_doc_id": 123,
  "current_blob_sha": "<git blob sha do arquivo atual>",
  "analysis": {
    "id": 9, "status": "completed", "source_blob_sha": "...", "external_job_id": "jobC",
    "score": 82, "grade": "B", "risk": "MEDIO",
    "counts": { "critical": 2, "warnings": 5, "recommendations": 11, "total": 18 },
    "engine": "TOTVS ADVPL/TLPP Code Analyzer", "engine_version": "img:1", "rules_version": "r1",
    "requested_at": "...", "started_at": "...", "completed_at": "...", "failed_at": null,
    "error_code": null, "error_message": null,
    "stale": false
  }
}}
```
- `state = never_analyzed` quando não há análise para a fonte; `analysis = null`.
- `state = outdated` quando a última análise concluída NÃO corresponde ao blob atual (`stale=true`) — o score antigo **não** é apresentado como atual.

### POST `/source-docs/{sourceDoc}/quality` — permissão **estrita** `source_docs.quality.run`
Dispara a análise da versão vigente (gate estrito = admin-only no MVP, como `reprocess`).
1. valida permissão (estrita) → 2. valida escopo (`canAccessDoc`, 404 se fora) → 3. resolve versão/blob atual →
4. obtém o conteúdo **server-side** (`GithubAppAuth::getFileWithSha`) → 5. impede job concorrente equivalente
(índice único parcial + reuse) → 6. cria vínculo local `queued` → 7. chama o A1 → 8. grava `external_job_id` →
9. devolve o estado. **O browser nunca envia o fonte.**
- `202` novo job (ou reuse de job em andamento); `200` reuse de análise concluída idêntica (`"reused": true`).
- Idempotência: mesmo (fonte, blob) em andamento → devolve o mesmo job (anti-duplo-clique). `force=true` ignora o reuse.

### GET `/source-docs/{sourceDoc}/quality/history` — permissão `source_docs.quality.view`
Lista as análises da fonte (todas as versões), com `stale` por item.

### GET `/source-docs/{sourceDoc}/quality/{analysis}/findings` — permissão `source_docs.quality.view`
Achados detalhados de UMA análise (adicionado p/ o A3). Proxy server-to-server ao A1 via `external_job_id`
(`SourceDocQualityService::getJob`); **achados NÃO são persistidos** no Postgres. Regras:
- **Escopo** (`canAccessDoc`) + **anti-IDOR**: a análise precisa pertencer a esta fonte, senão `404`.
- **Gating de código no BACKEND:** sem `source_docs.view_git`, remove QUALQUER campo que revele código
  (`snippet, source, code, excerpt, context, line_content, content, example`), preservando só metadados
  seguros (severidade, categoria, regra, título, descrição, linha, count). Resposta traz `view_git: bool`.
```json
{ "data": { "analysis_id": 9, "external_job_id": "jobC", "status": "completed", "view_git": true,
  "findings": [ { "severity": "CRITICAL", "category": "G2 - Performance", "rule": "CA_LOOP",
    "title": "Query em laço", "description": "...", "line": 182, "snippet": "..." } ] } }
```

## Segurança / server-to-server
- `Http::withToken(config('services.codeanalysis.token'))` — o token **nunca** aparece na resposta nem em log (testado).
- O CodeAnalysis roda em **rede privada**; o browser fala só com o Minutor. Autorização do usuário é 100% no Minutor.
- **`source_docs.view_git` NÃO é enfraquecido:** o usuário pode ter `quality.run` sem `view_git`; o backend obtém o
  fonte internamente para analisar, mas **nunca devolve o código ao browser** (testado).

## Resiliência (falhas distinguidas)
- **Antes de criar o job remoto** (indisponível/timeout/5xx/resposta inválida): o vínculo local vira `failed`
  (com `error_code`), **nunca fica `running` eterno**; a rota devolve `503`. A ficha da fonte não quebra.
- **Job remoto criado mas resultado ainda não chegou:** fica `queued/running`; o `GET` faz 1 consulta ao A1 e sincroniza.
- **Job remoto `failed`:** refletido como `state=failed` no `GET`.
- Sem retry cego no POST de criação (evita job duplicado). `GET` (consulta) é seguro.

## Auditoria
`SourceDocActionLog` (`action='quality_run'`, `status=ok|denied|skipped`, `params` sanitizado com `blob_sha`/`reason`),
mirando o padrão do `SourceDocActionController`. Registra quem/fonte/versão/quando/resultado. **Sem** cadastro de usuário no CodeAnalysis.

## Persistência híbrida
Tabela `source_doc_quality_analyses`: `source_doc_id`, `source_doc_version_id`, `source_blob_sha`, `external_job_id`,
`status`, `score`, `grade`, `risk`, `n_critical/n_warnings/n_recommendations/n_findings` (score **resumido**, não os findings),
`engine`/`engine_version`/`rules_version`, `requested_by/at`, `started/completed/failed_at`, `error_code/message`.
**Não duplica** cliente/nome/path/usuário/conteúdo (já são do Minutor). Snapshot completo de findings **não** é feito
(fica no CodeAnalysis); se um dia exigir imutabilidade/auditoria, sinalizar antes de adicionar JSON grande.

## Variáveis de ambiente (somente nomes)
`CODEANALYSIS_BASE_URL`, `CODEANALYSIS_SERVICE_TOKEN` (= token de serviço do A1), `CODEANALYSIS_TIMEOUT`, `CODEANALYSIS_ENABLED`.
Nenhum segredo no Git.

## Testes (18, 54 assertions — pgsql `minutor_c1test`)
view autorizado/negado · run autorizado/negado (gate estrito) · escopo/IDOR 404 · versão/blob correto enviado ·
vínculo à versão · blob mudou→outdated · matching→completed · concorrência reusa (1 chamada) · 5xx/timeout/resposta
inválida→failed+503 · remote failed refletido · completed persiste score/counts · histórico · **sem código no browser** ·
**token só no header, nunca na resposta** · fonte inexistente 404 · sem regressão no catálogo.
> Nota: `SourceDocActionTest::test_download_returns_file` falha por `phpoffice/phpword` ausente do vendor snapshot
> (requerido no composer.json, não instalado) — **pré-existente e alheio ao A2** (path de render/docx não tocado).

## Plano A3 (frontend — NÃO iniciar sem aval)
Aba **Qualidade** na ficha viva `SourceDocDetail` (não na órfã `SourceDocPanel`): score + faixas (críticos/alertas/
recomendações), lista de achados (severidade/categoria/linha/trecho), filtros, "Analisar novamente" (POST), histórico,
estado (`never_analyzed`/`queued`/`running`/`failed`/`outdated` com aviso "versão anterior"). Consome `GET/POST /quality` +
`/quality/history`. Achados detalhados podem vir de um endpoint de detalhe (a decidir no A3) que busca do A1 por `external_job_id`.
