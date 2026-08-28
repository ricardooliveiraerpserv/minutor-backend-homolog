# Runbook — Recuperação operacional do ciclo RPO hot (Conector C5)

> Para o **operador de plantão**. Como agir diante de cada estado de uma operação `rpo_promote`/`rpo_rollback`.
> Princípios inegociáveis: **C-2 (observado) é a autoridade do resultado físico**; **at-most-once** (nunca "executar de novo");
> **sem auto-rollback / sem auto-restart**; a **resolução humana fecha o incidente, não reescreve o passado para sucesso**.

---

## 1. Onde olhar

- **Console:** *Operações Protheus → Operações RPO* (`/operacoes-rpo`). Banner vermelho = incidente/congelamento.
- **Auditoria de uma operação:** botão **Auditoria** (ou `GET /prosight/operations/{id}/audit`) → cadeia completa + timeline correlacionada.
- **Estado observado:** consistência do target + `observed_current` (hash) por membro.

---

## 2. Matriz de FREEZE — o que cada estado bloqueia

Enquanto existir **qualquer operação não-terminal** no ambiente, o índice único `one_live_per_environment` impede criar **nova** operação (promote/rollback/lifecycle) → resposta `409 operation_in_flight`. Terminais liberam: `failed, expired, canceled, rejected, reconciled_success, reconciled_noop`.

| STATE | Target congelado? | Ambiente congelado? | Novo PROMOTE? | Novo ROLLBACK? | Nova op LIFECYCLE? | Resolve humano necessário? | Como desbloqueia |
|---|---|---|---|---|---|---|---|
| `pending_approval` / `approved` / `dispatchable` | sim | sim | ❌ 409 | ❌ 409 | ❌ 409 | não | aprovar→executar, ou **cancelar** |
| `claimed` / `execution_committed` / `executing` | sim | sim | ❌ | ❌ | ❌ | não | aguardar resultado/deadline → reconciliar |
| `verifying` / `reconciling` | sim | sim | ❌ | ❌ | ❌ | não | **reconciliar** (autoridade C-2) |
| `indeterminate` | sim | sim | ❌ | ❌ | ❌ | não (ainda) | **reconciliar**; C-2 decide success/noop/incidente |
| `contradicted` | **sim** | **sim** | ❌ | ❌ | ❌ | **SIM** | investigar + **/resolve** (reason) |
| `partial_apply` *(status=contradicted)* | **sim** | **sim** | ❌ | ❌ | ❌ | **SIM** | investigar membros divergentes + **/resolve** |
| `recovery_failed` *(status=contradicted)* | **sim** | **sim** | ❌ | ❌ | ❌ | **SIM** | investigar disponibilidade + **/resolve** |
| `unresolved` | **sim** | **sim** | ❌ | ❌ | ❌ | **SIM** | investigar (sem coleta correlacionada) + **/resolve** |
| terminal (`reconciled_success`/`reconciled_noop`/`failed`/…) | não | não | ✅ (se estado atual permitir) | ✅ | ✅ | não | — |

> **Não existe "tentar de novo"** para uma operação destrutiva ambígua. Um novo `promote`/`rollback` **não** contorna o incidente — ele é bloqueado até resolução governada.

---

## 3. O que fazer, por estado

### `indeterminate` (perdeu-se a comunicação após o claim)
- Significa: o efeito **pode** ter ocorrido. **NUNCA** recrie a operação.
- Ação: peça uma coleta correlacionada (o agente sobe inventário com `trigger.operation_id`) e **reconcilie**. O C-2 decide:
  - todos os membros no `to_hash` + disponíveis → `reconciled_success` (efeito ocorreu 1×);
  - todos no `from_hash` → `reconciled_noop` (efeito não ocorreu);
  - divergência → incidente (abaixo).

### `contradicted` (C-2 contradiz o esperado; ex.: hash inesperado, ou agente ok × observado divergente)
- **Não toque no ambiente às cegas.** Abra a **Auditoria** e veja `from/to`, `execution_committed`, `effect_started` e os hashes observados por membro.
- Decida o desfecho real **fora de banda** (inspeção do ambiente). Depois **/resolve** com `reason` (ver §4).

### `partial_apply` (membros do target divergem: uns no `to`, outros no `from`)
- Indica publicação **parcial** / modelagem inconsistente do target. **Não** crie outra publicação.
- Investigue por que os membros divergem (topologia, unidade de publicação). Alinhe o ambiente manualmente se necessário e **/resolve**.

### `recovery_failed` (RPO=`to` aplicado, mas um AppServer não retornou disponível)
- hot **não** reinicia AppServer — se caiu, é incidente de disponibilidade. **Sem** restart automático.
- Restabeleça a disponibilidade pelo procedimento padrão de infra; confirme por observação; **/resolve**.

### `unresolved` (sem coleta correlacionada/causal dentro da janela)
- O sistema não conseguiu concluir. Verifique presença/coleta do agente. Force uma coleta correlacionada e **reconcilie**; se ainda inconclusivo, **/resolve**.

---

## 4. Resolução HUMANA governada (`/resolve`)

Fecha o incidente e **remove a trava**. Exige permissão `prosight.operations.rpo.approve` (mesma do aprovar/reconciliar) e escopo do cliente (anti-IDOR).

- **`reason` é OBRIGATÓRIO.** Descreva o que foi apurado e a ação tomada fora de banda.
- **Disposições possíveis:** `failed` (falha reconhecida / incidente tratado) ou `noop` (nenhuma mudança tomou). **NÃO existe `success`** — o resultado físico "sucesso" só vem do C-2; um humano nunca reescreve para sucesso.
- **Preserva a evidência:** `reconciliation_state` observado (`partial_apply`/`recovery_failed`/`contradicted`/`unresolved`) permanece registrado; a resolução grava `resolution = {disposition, reason, resolved_by, at, before:{...}}` e **acrescenta** um evento `operation_resolved` à timeline (nada é apagado).
- **Efeito:** a operação vai a terminal (`failed`/`reconciled_noop`), o ambiente é **descongelado**. Uma nova operação só é possível se o **estado atual observado** também permitir (ex.: rollback exige target consistente no `from` e known_good válida).

**Exemplo (API):**
```
POST /api/v1/prosight/operations/{id}/resolve
{ "resolution": "failed", "reason": "Inspecionei APP02: RPO já estava em B; rollback manual não necessário. Incidente encerrado." }
```

**O que resolver NÃO faz:** não executa efeito, não dispara rollback/restart, não recria operação, não altera o resultado observado, não vira "sucesso".

---

## 5. Quando criar uma NOVA operação (depois de resolver)

Só depois que o incidente estiver **terminal** e o **estado atual observado** permitir:
- **Rollback** para uma known-good: exige target consistente, `observed_current == from` (≠ destino), qualificação **válida** (não revogada) escolhida explicitamente. Se o target já estiver no destino → `already_at_rollback_target` (nada a fazer).
- **Promote**: exige target consistente, artefato registered compatível, `from ≠ to`, capability `hot` disponível.
- A criação **reavalia** tudo transacionalmente e o dispatch **revalida** de novo — se o mundo mudou desde a decisão, a operação é bloqueada (crie outra).

---

## 6. Não faça (fronteira C5)

- ❌ recriar/repetir uma operação `indeterminate`/`contradicted` ("tentar de novo").
- ❌ forçar `success` numa resolução.
- ❌ restart/stop/start para "consertar" um `recovery_failed` via C5 (isso é C5.2b, **bloqueado**).
- ❌ editar `rpo_artifacts`/`rpo_qualifications`/`resolution` no banco à mão.
- ❌ apagar evidência (`connector_events`, snapshots, resolution).
- ❌ expor/registrar path, credencial, bytes de RPO ou staging handle.

---

## 7. Escalonar

Se após resolução o ambiente permanecer inconsistente, ou houver suspeita de divergência de RPO não explicável por observação, **PARE** e escale para a engenharia do Conector — **não** improvise efeito destrutivo.
