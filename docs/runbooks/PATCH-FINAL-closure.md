# PATCH-FINAL — Product Closure / Freeze

> Estado: **PATCH PRODUCT — FINAL CLOSED / FROZEN** · **PATCH PHYSICAL — VALIDATION PENDING**
> Escopo: produto Patch (P1 fundação, P2 execução governada, P3 handoff C5). Fixture/simulated. Sem física TOTVS.

Este documento congela formalmente o produto Patch e registra os **bloqueadores físicos** e as **propriedades
auditadas**. Nenhuma funcionalidade nova foi adicionada em PATCH-FINAL.

## Boundary do produto (congelado)

```
Compile ──→ Candidate ─┐
                       ├──→ C5 REGISTER ──→ Qualify ──→ Promote   (Qualify/Promote SÓ no C5)
Patch   ──→ Candidate ─┘
```

- **Produzir ≠ Publicar.** Patch produz artefato candidato; o C5 registra; qualificação e promoção seguem
  exclusivamente sob a autoridade do C5. Registrar **não** qualifica; qualificar/publicar **não** pertencem ao Patch.
- Patch **nunca** altera target nem RPO ativo. Patch **nunca** chama qualify/known_good/promote/rollback.

## Invariantes provados (Feature + live gate env1×env3)

| Invariante | Prova |
|---|---|
| Só execução completa (3/3 + artifact_verified + causalidade) gera candidate | PatchP2Test, PatchP3Test |
| partial / failed / indeterminate / contradicted → **zero candidate** | PatchP2Test, PatchFinalAuditTest |
| Só candidate válido (execução ST_CANDIDATE) registra no C5 | PatchP3Test |
| Registro C5 **idempotente** (mesmo rpo_artifact_id; sem revisão extra) | PatchP3Test, PatchFinalAuditTest |
| Registro **não** qualifica/promove; RPO ativo inalterado | PatchP3Test, PatchFinalAuditTest |
| Input/base/batch/candidate **imutáveis** | PatchP1Test, PatchP3Test |
| classification (test/demo/operational) **nunca** relaxa segurança | PatchP1Test |

## Cross-producer workspace safety (`connector_workspace_locks`)

- **Identidade opaca** do workspace (`workspace_unit_id`, agent-derived; nunca path/hash-de-path).
- **Fence monotônico** por workspace + **lease** (`lease_expires_at`).
- **Only-current-authority-crosses-barrier**: `patch_effect_started` revalida `fence == lock ativo && lease válida`;
  processo antigo (fence anterior) → `fenced_out`. UNIQUE ACTIVE sozinho não resolve crash/lease — fence/lease resolve.
- **INDETERMINATE segura o workspace**: lease expirada mid-efeito não é reapável; exige `resolve` explícito.

### 🚫 PHYSICAL BLOCKER (registrado formalmente)

> **LiveCompileAdapter MUST acquire the same cross-producer `connector_workspace_locks` (fence + lease) before
> C6 live can be enabled.** Enquanto isso não existir, C6 live permanece `unavailable`.

Motivo: uma execução física de Compile e uma de Patch sobre a mesma unidade mutável não podem coexistir. O lock
cross-producer já existe e é adquirido pelo Patch (P2). O C6, hoje simulated-instantâneo/live-unavailable, **não**
segura o workspace — portanto **não** há conflito atual e **não** se deve alterar o C6 agora. A integração do
LiveCompileAdapter ao mesmo lock é requisito de **C6-PHYSICAL**, a ser feito junto do protocolo físico TOTVS.

## Durabilidade da proveniência (auditada)

A partir **somente** de um `rpo_artifacts` produzido por Patch, a cadeia resolve de forma inequívoca e imutável:

```
rpo_artifacts.provenance ("producer=patch …") + compatibility.producer=patch
  └─(rpo_artifact_id, imutável)─▶ patch_artifact_candidates   (append-only)
        ├─ candidate_digest (= rpo_artifacts.hash)
        ├─ base_rpo_digest · batch_digest
        ├─ provenance.item_digests[] (identidades/digests ordenados)
        ├─ capability_adapter_version · classification · simulated
        └─(patch_execution_id)─▶ patch_executions (execution_id, fence, journal)
```

- **Referência durável e imutável**: `patch_artifact_candidates.rpo_artifact_id` é gravado uma vez no handoff e
  nunca alterado; a linha é append-only.
- **Sem cascade que quebre a cadeia**: nenhuma FK `ON DELETE CASCADE` nas tabelas da cadeia
  (`patch_*`, `rpo_artifacts`) — provado por `PatchFinalAuditTest::test_no_cascade_can_break_provenance_chain`.
- **Sem delete no domínio**: nenhum endpoint/serviço apaga candidate/execution/artifact (append-only).
- Não se duplica a proveniência inteira dentro do C5 — a referência é suficiente porque é durável e imutável.

## Segurança (auditada)

- Payloads/respostas/proveniência/FE **sem** filesystem path, INI, SpecialKey, command line, PTM bytes, RPO bytes,
  credenciais/tokens (`PatchFinalAuditTest::test_security_scan_no_sensitive_material`).
- Anti-IDOR server-side (env→customer revalidado; customer_id nunca autoridade); cross-customer/cross-environment
  → 404 (PatchP2Test/PatchP3Test).

## Honest-mode (auditado)

- `fixture` / `simulated` / `live` inequívocos. **`LivePatchAdapter` = unavailable**; **sem fallback live→simulated**
  (execução live → 422 `live_unavailable`, nunca coage para simulated). FE marca **"Simulado"** e nunca
  "Publicado/Aplicado/Ativado" para execução Patch.

## Test isolation

- Guard em `AppServiceProvider` bloqueia `migrate:fresh`/`migrate:refresh`/`db:wipe` contra conexão fora de
  `database.disposable_test_databases` (default `:memory:`) durante testes. `minutor_c1test` **não** é descartável →
  protegido. Teste negativo: `PatchP3Test::test_destructive_db_reset_guard`. Nenhum reset destrutivo é executado em
  homolog/prod para provar isto.

## Residual risks / backlog (registrados; NÃO abrir sem aval)

- **C6-PHYSICAL / PATCH-PHYSICAL**: protocolo físico TOTVS real (artifact digest real, base RPO real, lock
  Compile↔Patch compartilhado). O gate muda de natureza (prova execução real, não simulada).
- **ENVIRONMENT-LIFECYCLE**: soft-delete de ambiente + subordinados (hoje sem FK/cascade — a cadeia de proveniência
  não quebra, mas um lifecycle explícito ainda não existe).

## Environment final state (homolog)

- Nenhuma execução Patch **indeterminada** artificial pendente (os cenários de fencing/indeterminate rodaram apenas
  em Feature tests locais, nunca em homolog).
- Os live gates deixaram registros append-only (inputs/requests/executions/candidates/artefatos C5 `classification=test`)
  em workspaces descartáveis únicos — **evidência**, não removida. Locks pré-efeito de sondagem têm lease expirada
  (reapáveis, não retêm o workspace). Nenhum lock indevidamente retido.

## Proibições em vigor

Não iniciar PATCH-PHYSICAL. Não iniciar C6-PHYSICAL. Não habilitar LivePatchAdapter/LiveCompileAdapter.
Não alterar C5 / RPO-DISCOVERY / ENV-HUB. Não criar funcionalidade nova de produto Patch.
