# CP-PHYSICAL-0 — Compile + Patch Physical Proof Protocol (DESIGN ONLY)

> **DESIGN ONLY. ZERO execução TOTVS.** Não implementa/habilita LiveCompileAdapter/LivePatchAdapter, não
> promove/qualifica, não toca produção, não inicia P0–P7. Este documento define o protocolo experimental para,
> **no futuro e sob aval**, provar Compile e Patch reais em TOTVS.
>
> **Classificação final: NOT READY — BLOCKERS FOUND** (ver §11).

Compile e Patch chegaram ao mesmo ponto de produto (producer → candidate → C5 register). A física compartilha
**uma** questão de infraestrutura: *obter autoridade exclusiva sobre um workspace, provar sua base, executar uma
transformação e produzir um RPO candidato verificável*. Este desenho é **conjunto** para não criar dois mecanismos
físicos divergentes.

---

## §1 Physical Architecture

```
                         ┌──────────────── ON-PREM (Connector real) ────────────────┐
 Minutor (cloud)         │  workspace/compilador físico  ·  RPO-base  ·  fontes/PTM  │
 ───────────────         │                                                          │
 identidades/digests ──▶ │  seed → prova base → (compile|apply) → defrag → digest    │
 capability/lock/journal │                                        │                 │
 ◀── digest+diagnóstico  │  bytes/paths/INI/SpecialKey NUNCA saem ◀┘                 │
 (SANITIZADO)            └──────────────────────────────────────────────────────────┘
        │
        └─▶ ArtifactCandidate ─▶ C5 REGISTER ─▶ STOP   (qualify/promote só no C5, manual)
```

Princípios herdados e congelados (não reabrir): payload = identidade/digest/capability (nunca bytes/paths);
adapter resolve bytes on-prem de fonte confiável; at-most-once por `execution_id`; barrier + journal durável;
"não recebi resultado" ≠ "execute de novo"; produzir ≠ publicar; C5 é a única autoridade de publicação.

---

## §2 Workspace / Lock Contract (CONGELADO para física — B)

Compile físico **e** Patch físico usam **obrigatoriamente** `connector_workspace_locks` com:
`environment_id` + `workspace_unit_id` (opaco, agent-derived; nunca path) + `producer` (`compile`|`patch`) +
`execution_ref` (execution_id) + **`fence_token`** (monotônico por workspace) + **`lease_expires_at`** +
`reconcile_required`.

Regras (já implementadas p/ Patch em P2; a especificar p/ Compile live):
- **1 execução mutável ativa por workspace, cross-producer.** Aquisição antes de qualquer preparação mutável.
- **Effect barrier fenced**: só atravessa quem tem `fence == lock ativo && lease válida`. Fence anterior → `fenced_out`.
- **Lease expirada pré-efeito** → reapável (novo fence). **Mid-efeito** → `indeterminate` que **congela** o workspace
  (não devolve autoridade até `resolve` explícito).

### 🚫 Integração obrigatória do LiveCompileAdapter (PHYSICAL BLOCKER — ver §11)
Hoje o C6 (simulated/live-unavailable) executa **inline** e **não** adquire o lock. Antes de habilitar Compile live,
o `CompileService::execute`/`LiveCompileAdapter` DEVE, no caminho live: (1) adquirir o mesmo `connector_workspace_locks`
para o `workspace_unit_id` alvo com fence+lease; (2) revalidar o fence no effect barrier; (3) liberar/segurar
conforme o resultado (terminal libera; indeterminate segura). **Não alterar C6 agora** — isto é trabalho de
implementação de C6-PHYSICAL, especificado aqui.

**Provas exigidas no protocolo (workspace):** Compile bloqueia Patch no mesmo workspace; Patch bloqueia Compile;
workspaces distintos são independentes; old owner/fence não atravessa o barrier; indeterminate mantém congelado.

---

## §3 Base Seeding (E)

Requisito funcional (descoberto em PATCH-0, preservado): **o workspace deve começar exatamente no RPO-base
aprovado/atual** antes de compilar ou aplicar patch. Mecanismo = **implementation-specific on-prem**, NÃO
obrigatoriamente `copy-rpo-to-compiler` do legado.

Contrato de seed (a cumprir pelo adapter físico, on-prem):
1. Resolver o RPO-base aprovado (digest conhecido pelo Minutor via C2 observed / C5 known base).
2. Preparar o workspace nesse estado exato pelo mecanismo TOTVS/on-prem homologado.
3. **Prova de base**: digest físico do workspace == `base_rpo_hash` aprovado, **antes** do effect barrier.
   Divergência → `base_mismatch` terminal → zero candidate (nenhum efeito).
4. **Nenhum path ou byte cruza para o Minutor** — só o veredito (match/mismatch) + digest.

Fonte da base: **C2 observed base** (topologia/RPO observado pelo Connector) reconciliada com a base aprovada no
C5. O adapter físico prova localmente; o Minutor confia no digest, não no conteúdo.

---

## §4 Compile Physical Protocol (C)

Máquina (todos os marcadores no journal durável; at-most-once por `execution_id`; barrier antes do efeito):

```
C2 observed base
 → acquire workspace lock (fence+lease)        [§2]
 → seed/preparação do workspace                [§3]
 → PROVA local da base (== approved)           [§3]  — mismatch → base_mismatch → zero candidate
 → source pin (source_blob_sha/commit)         [CompileContext — já existe no C6]
 → EFFECT BARRIER (revalida fence)
 → physical compile (per capability/flags/defrag)
 → digest físico do artefato (ArtifactIdentity)
 → ArtifactCandidate (unit: standalone|rpo_apo_full|rpo_apo_incremental|unknown)
 → C5 REGISTER (handoff governado, já existe)
 → release lock → STOP
```

**Cenário negativo obrigatório (P4):** `physical compile failure` → **diagnóstico SANITIZADO** (contagens/classes,
sem log bruto/path) → `failed` → **zero candidate** → release lock. Nunca "fake success" (contrato CompileAdapter).

Reuso: `CompileRequest`/`CompileExecution`/`ArtifactCandidate`/`CompileContext`, `CompileAdapter` interface
(retorno `CompileOutcome` sanitizado), handoff C6.7 → C5. A ÚNICA adição é a aquisição do lock no caminho live (§2).

---

## §5 Patch Physical Protocol (D)

Máquina (idêntica em espírito; reusa integralmente P2/P3):

```
C2 observed base
 → acquire workspace lock (fence+lease)         [§2, já implementado em P2]
 → seed do workspace                             [§3]
 → PROVA base_rpo_hash (== approved)             [§3] — mismatch → base_mismatch → zero candidate
 → PTM physical pin/digest (== pinned item_digest)
 → EFFECT BARRIER (revalida fence)               [P2]
 → apply (lote ordenado, journal por item)       [P2]
 → defrag conforme capability
 → candidate digest físico
 → ArtifactCandidate → C5 REGISTER (P3)          → release lock → STOP
```

**Cenário partial/failure controlado (P6):** PTM1 commit / PTM2 falha / PTM3 nunca inicia → `partial` → **zero
candidate** → recovery = re-seed + nova execução. **Partial NUNCA gera candidate** (já provado simulado em P2).
⚠️ Ver §9/§10: só executar P6 físico se o re-seed do workspace estiver **comprovadamente** disponível; senão
**BLOCKED BY SAFETY**.

---

## §6 Capability Matrix (F)

Capability **real, versionada, declarada pelo agente** (fail-closed; nada inferido do legado; combinação não
suportada → `unavailable`).

| Campo | Compile (`source_compile`) | Patch (`rpo_patch`) |
|---|---|---|
| contract_version | allowlist (`supported_capabilities`) | allowlist |
| version/release TOTVS | declarado (compiler_metadata) | `compatible_release` |
| workspace semantics | `workspace_units[]` (opacos) | `workspace_units[]` |
| requirements | `requires_stop`/`exclusive`/`restart`/`defrag` | idem + `supported_strategy` |
| languages | `advpl,tlpp` (contrato) | n/a |
| unit de artefato | standalone / rpo_apo_full / rpo_apo_incremental | rpo (base+ptm) |
| defrag | conforme capability (não presumir) | conforme capability |

**Gates de disponibilidade live (ambos):** `live_ready=true` (só após validação física) **E** capability declarada
**E** contract_version suportada. Qualquer falha → `unavailable` (sem fake, sem fallback). Combinação
stop/exclusive/restart NÃO é inferida da UI nem do legado — é declarada pela capability.

---

## §7 Evidence Matrix (G)

Para **cada** prova P0–P7, registrar (append-only, journal + eventos), **SANITIZADO**:

`execution_id` · `workspace_unit_id` · `fence_token` · `base digest (approved + observed)` · `input digest`
(source_blob_sha | ptm item_digests ordenados) · `candidate digest` · `capability/adapter version` ·
`timestamps` (por marcador) · `result` · `sanitized diagnosis`.

**Proibido em qualquer evidência:** filesystem path · INI · SpecialKey · command line · PTM bytes · RPO bytes ·
credentials/tokens. (Auditado automaticamente — mesmo scanner de `PatchFinalAuditTest`.)

---

## §8 Recovery Protocol (I) — pré-requisito de QUALQUER efeito físico

Antes de qualquer prova com efeito, DEVE existir e estar comprovado:
- **Restaurar workspace** ao RPO-base aprovado (mecanismo on-prem homologado) + **provar** restauração (digest == base).
- **Crash / lost-ACK**: execução vira `indeterminate` (não retry); workspace **congelado** até `resolve`.
- **`indeterminate` → resolve → re-seed** antes de nova execução no mesmo workspace.
- **Lock release**: só em terminal comprovado (candidate/failed/partial/base_mismatch) ou resolve; **nunca** em
  timeout de transporte.
- **Nenhuma prova destrutiva inicia sem recovery definido e testado** (re-seed provado em ambiente isolado primeiro).

---

## §9 Safety Preconditions (A)

- Ambiente **exclusivamente homolog/test**; Connector real; versão/release TOTVS conhecida.
- Workspace/compilador físico identificado localmente; `workspace_unit_id` opaco; RPO-base conhecido.
- Fontes controladas (Compile) e PTM controlado (Patch); **possibilidade comprovada de restaurar/re-seedar**.
- **Zero risco ao RPO ativo de produção** (produção fora de escopo; Patch/Compile nunca tocam target ativo).
- **PATs comprometidos REVOGADOS** (condição administrativa paralela — ver §11 / SECURITY-HOTFIX PAT).

---

## §10 P0–P7 Gate Matrix (H)

| Fase | Objetivo | Efeito | Pré-condição de segurança |
|---|---|---|---|
| **P0** | readiness / no-effect (capability, lock adquire/solta, availability) | nenhum | §9 mínimas |
| **P1** | workspace + lock/fencing (Compile↔Patch mutex, old-owner fenced, indeterminate congela) | nenhum (lock only) | §2 |
| **P2** | base seed + prova (match e mismatch→base_mismatch) | seed reversível | **§8 recovery provado** |
| **P3** | Compile físico POSITIVO → candidate → C5 register | efeito no workspace | §8 + §3 |
| **P4** | Compile físico NEGATIVO → sanitized diag → zero candidate | efeito parcial no workspace | §8 |
| **P5** | Patch físico POSITIVO → candidate → C5 register | efeito no workspace | §8 + §3 |
| **P6** | Patch físico NEGATIVO/partial seguro → zero candidate | **potencialmente não-recuperável** | **§8 re-seed comprovado; senão BLOCKED BY SAFETY** |
| **P7** | handoff C5 + confirmação de ZERO publicação/qualify/promote automáticos | nenhum (registro) | §7 |

**P6 não é assumido seguro.** Se produzir `partial` deliberado puder comprometer o workspace além de recuperação
comprovada, **classificar BLOCKED BY SAFETY** e definir alternativa (ex.: partial simulado por injeção controlada
que não corrompa o RPO físico, ou snapshot/rollback de workspace antes da prova).

---

## §11 BLOCKERS (impedem entrar em P0)

1. **LiveCompileAdapter não integrado ao workspace lock** (§2). Requer implementação C6-PHYSICAL (não feita; não
   fazer agora). Sem isso, Compile live não pode coexistir com Patch com segurança.
2. **Mecanismo de base-seeding / re-seed on-prem homologado inexistente/comprovado** (§3/§8). Sem re-seed provado,
   nenhuma prova com efeito (P2+) inicia; **P6 fica BLOCKED BY SAFETY** por padrão.
3. **Ambiente físico TOTVS homolog dedicado não provisionado** (§9): Connector real + workspace/compilador físico
   + versão/release conhecida + fontes/PTM controlados.
4. **Capability física real não declarada** (§6): `source_compile`/`rpo_patch` reais com requirements
   (stop/exclusive/restart/defrag) e versão homologada. Hoje só simulada.
5. **`live_ready` permanece false** para ambos (design; não habilitar).
6. **PATs comprometidos ainda não revogados** (SECURITY-HOTFIX PAT — OPEN). Condição administrativa: revogar antes
   de qualquer validação física sensível.

---

## §12 Classificação

**NOT READY — BLOCKERS FOUND.** O produto (simulado) está fechado e simétrico; a **arquitetura física** está
desenhada e conjunta (um único mecanismo de autoridade-de-workspace + prova-de-base + transformação + candidate).
Entrar em P0 exige resolver os 6 blockers do §11 — nenhum deles é executado nesta fase.

**DESIGN ONLY concluído. STOP.** Não iniciar P0–P7. Não implementar/habilitar Live adapters. Não tocar produção.
Não promover/qualificar. Próxima ação só com aval explícito, e começando por P0 (no-effect) após os blockers.

---

## §13 P0 Entry Gate (checklist para AUTORIZAR P0 — no-effect)

> P0 **não** exige base-seed implementado (isso é P2). P0 exige infraestrutura suficiente para **provar que
> estamos olhando o ambiente certo**. Objetivo de P0 (congelado): **provar identidade e isolamento ANTES de provar
> transformação** — observar/correlacionar Minutor → Connector → TOTVS → AppServer/compiler → `workspace_unit_id`
> → RPO observed digest, demonstrando que nenhum passo depende de enviar path/INI/PID/command/RPO bytes/credentials
> ao Minutor. Primeira execução 100% no-effect.

```
[ ] 3 PATs comprometidos REVOGADOS (SECURITY-HOTFIX PAT)          ← ADMINISTRATIVE (bloqueia P0)
[ ] autenticação Git segura substituta configurada (GitHub App/SSH min-scope)
[ ] ambiente TOTVS dedicado identificado
[ ] ambiente confirmado HOMOLOG/TEST, NUNCA produção
[ ] Connector real enrolado e online
[ ] versão/release/patch TOTVS observados
[ ] AppServer/compiler físico identificado pelo Connector
[ ] workspace_unit_id real e opaco observado
[ ] workspace confirmado NÃO ser RPO ativo de produção
[ ] RPO-base de teste conhecido
[ ] recovery owner definido
[ ] backup/re-seed possível EM PRINCÍPIO (implementação = P2)
[ ] capability permanece physical_ready=false
[ ] LiveCompileAdapter unavailable
[ ] LivePatchAdapter unavailable
```

**Para montar/autorizar P0, o operador traz:** o estado de `GET /prosight/environments/{env}/physical-readiness`
(deve manter `physical_ready=false`, `live_ready=false`, agora com `connector_ready`/`workspace_ready` refletindo o
ambiente real) + a identificação **sanitizada** do ambiente/Connector/workspace (identidades opacas + digests; sem
path/INI/host/credential).

## §14 GATE físico do Compile source-only (governança — enforce em C6-PHYSICAL)

Hoje: *Compile source-only (sem `workspace_unit_id`) → sem lock* — aceitável **enquanto** a execução NÃO modifica um
RPO/workspace físico compartilhado (simulated/live-unavailable não modificam nada).

**Regra congelada (enforce quando o LiveCompileAdapter compilar contra RPO físico):**

> **Nenhum Compile FÍSICO que possa modificar RPO pode atravessar o effect barrier sem `workspace_unit_id` + lock +
> fence válidos.** Para o caminho live, `workspace_unit_id` deixa de ser opcional e a ausência dele é `blocked`
> (nunca "sem lock"). Isso impede que o caminho legado source-only vire uma exceção que contorne a governança de
> exclusão física. (O caminho source-only permanece válido apenas para execuções comprovadamente sem efeito em RPO
> físico compartilhado.)
