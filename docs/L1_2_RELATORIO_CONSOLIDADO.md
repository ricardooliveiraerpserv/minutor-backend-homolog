# L1.2 — Relatório Consolidado (CodeAnalysis × Minutor, homolog)

**Data:** 2026-08-26 · **Ambiente:** homologação · **Resultado global:** **PASS COM RESSALVAS**
(8/8 capacidades PASS · R1 corrigido e validado · R2 auditado sem implementar · limpeza concluída)

---

## 1. C1–C8 — capacidades LIVE e evidências

| Caso | Capacidade | Resultado | Evidência |
|---|---|---|---|
| **C1** | Permissões / perfis (coordenador etc.) — gating inalterado pela integração | **PASS** | UI por perfil + checagem read-only de permissões; nenhum perfil ganhou/perdeu acesso |
| **C2** | Gating `source_docs.view_git` (snippets) — mascaramento **server-side** | **PASS** | com view_git vê snippet/"Ver no código"; sem view_git recebe score/findings **sem** código |
| **C3** | Anti-IDOR / escopo de cliente | **PASS** | endpoints quality/history/findings de fonte fora do escopo → negação controlada, sem vazar existência |
| **C4** | Outdated / blob desatualizado | **PASS** | reanálise vincula ao blob corrente; histórico preserva a análise do blob antigo; "outdated" = análise.blob ≠ **git HEAD ao vivo** (não o ponteiro de versão) |
| **C5** | Timeout BFF→CA | **PASS** | `analyzer_timeout` estruturado (id 19, "output.json não gerado"); sem 500 cru; job preservado — ver **CA-R1b** |
| **C6** | Serviço caindo / indisponível | **PASS** | `job_lost` com **reconciliação guardada** (ids 12–18); 503/failed controlado; sem análise "fantasma"; recuperação limpa |
| **C7** | Concorrência (serialização + dedup inflight) | **PASS** | `Semaphore(1)` serializa (P3); dedup por índice único parcial + checagem inflight no `run()` |
| **C8** | force / reuse | **PASS** | reuse devolve completed idêntico sem novo job; `force` cria nova execução; histórico coerente |

As análises da **fonte 8** ficaram **retidas como evidência técnica** (decisão): `12,13,14,16,17,18` = `job_lost`; `19` = `analyzer_timeout`; `15` e `26` = `completed` com findings persistidos (P2).

---

## 2. R1 — Histórico "stale" no FE, corrigido e validado SEM F5

- **Defeito:** o `QualityTab` recarregava o Histórico só no mount e no `doRun`. Ao o polling atingir estado terminal, ele parava o polling mas **não** refazia o Histórico → a linha ficava congelada em `queued/running` até F5.
- **Correção (mínima, localizada — única exceção ao Frontend Freeze):** em `applyView`, ao atingir terminal **durante** o polling (`wasPolling = pollRef.current !== null`), chamar `loadHistory()`. Fetch one-shot; sem novo interval; sem redesenho; sem mudança de layout/contratos. Commit **`0ebdaaa6`** (FE homolog, Live).
- **Validação LIVE (headless, auth por token, backend definitivo):** `queued` (t+8.7s) → estático em `queued` durante o `running` → **`completed` sozinho (t+35.8s), sem nenhum `reload()`**. Painel principal `0/100·F` == topo do Histórico (`completed`, `0/100·F`, blob `c0158b0d`). **6/6 critérios PASS.**

---

## 3. R2 — Tentativa com `CODEANALYSIS_ENABLED=false` gera `failed/disabled` (AUDITADO, não implementado)

**ATUAL** — `SourceDocQualityController::run()`:
1. faz o git-fetch do conteúdo (linha 73);
2. **cria o registro `source_doc_quality_analyses` (queued) na linha 104, INCONDICIONALMENTE**;
3. só então chama `service->analyze()` (linha 126), onde vive a checagem `enabled()`;
4. com `ENABLED=false`, `analyze()` lança `CodeAnalysisException('disabled')` → catch (132) faz `UPDATE status=failed, error_code='disabled'` (mesma via de falha remota) → **503**.
Achado adicional: `stateView` **não emite `service_enabled`** → a checagem `r.data.service_enabled === false` no FE é **código morto**; o estado "desabilitado" só é descoberto ao clicar Analisar — que é o clique que cria a linha `failed/disabled`.

**PROBLEMA** — cada tentativa com serviço desabilitado deixa uma linha `failed/disabled` no **Histórico** (ruído que não é falha do analyzer), além de um git-fetch desperdiçado e semântica enganosa.

**PROPOSTA (mínima)** — guarda no topo de `run()`, **antes** de qualquer create/fetch:
```php
if (! $this->service->enabled()) {
    $this->audit($doc, 'skipped', ['reason' => 'service_disabled'], $request->user()?->id);
    return response()->json([
        'message' => 'Análise de qualidade desabilitada neste ambiente.',
        'error'   => 'disabled',
        'data'    => $this->stateView($doc, $this->resolver->resolve($doc)['current_blob_sha'] ?? null, null),
    ], 503);
}
```
Registrar a tentativa no **`SourceDocActionLog`** (via `audit('skipped','service_disabled')`), **não** na tabela de análises. (Opcional, separado: emitir `service_enabled` no `stateView` para o FE mostrar "desabilitado" já no load — corrige o código morto, mas é diff maior.)

**IMPACTO** — FE já trata 503 → `setUnavailable` (nenhuma mudança de FE necessária no fix mínimo). Sem schema/migration/analyzer. Testes atuais fixam `enabled=true` → **não quebram**; recomenda-se **adicionar** teste: disabled → 503 + **zero** linhas em `source_doc_quality_analyses` + audit `skipped`.

**DIFF MÍNIMO** — ~6–8 linhas no `run()` (guarda antecipada). **Não implementado** — aguarda aval.

---

## 4. Correções arquiteturais realizadas durante o L1.2

- **P2 — findings duráveis no Postgres:** autoridade histórica passa a ser o BFF/Postgres (`source_doc_quality_findings`, só metadados), sobrevivendo a restart/deploy do CA (store efêmero). `persistFindings` idempotente.
- **P3 — serialização in-process:** `threading.Semaphore(1)` no runner (o `gunicorn -w1` **não** serializa threads daemon); `_INFLIGHT` deduplica o mesmo blob.
- **`job_lost` — reconciliação guardada (anti-404-prematuro):** um 404 só vira `job_lost` se o CA estiver comprovadamente **healthy** + 2º 404 + janela de graça; restarting/unreachable/5xx/timeout ⇒ mantém o estado. Sem `sleep` bloqueante.
- **CA-R1b — timeout estruturado + kill do grupo de processos** (ver §5).
- **HEALTHCHECK tolerante no Docker do CA** — a correção real do que parecia OOM (ver §5).
- **R1** — refetch do Histórico no terminal do polling (§2).

---

## 5. CA-R1a e CA-R1b

**CA-R1a — acoplamento liveness × analyzer in-process (RISCO ARQUITETURAL ABERTO).**
O crash em análise pesada **não era OOM**: foi **health check agressivo** derrubando o container (Render `exit 137` = SIGKILL após "health check failed"), interpretado inicialmente como falta de memória. O analyzer roda **in-process** e bloqueia; o health check apertado matava o container no meio. **Pico medido ~335 MiB.** **Mitigado por _health check tolerante_** — foi a mudança do health check que destravou a análise. Os **2 GB** foram usados para **investigação (eliminar memória como variável) e margem operacional**; **não fazem parte da solução** — os próprios testes mostraram que **aumentar memória NÃO resolveu o SIGKILL**. **Não resolvido** arquiteturalmente.

**CA-R1b — analyzer_timeout estruturado (IMPLEMENTADO e testado).**
Timeout do analyzer mata o **grupo de processos** (`start_new_session=True` + `os.killpg` SIGTERM → graça → SIGKILL; sem bash/java órfão), retorna `error_code=analyzer_timeout` estruturado (sem parsing de string), **preserva o job**. BFF (`applyRemote`) consome o `error_code` estruturado. Evidência: análise id 19 (`analyzer_timeout`).

---

## 6. Segurança

- **Anti-IDOR (C3):** escopo por cliente nos 3 endpoints; negação sem vazar existência/conteúdo.
- **`view_git` (C2):** mascaramento de snippet/código **server-side**; quem não tem permissão nunca recebe código.
- **Sem código-fonte no Postgres (§7).**
- **Server-to-server:** token do CA nunca logado; BFF só repassa `filename`+`content`+`context` opaco (não envia identidade de negócio como verdade).
- **Higiene de credenciais (limpeza):** token admin de bootstrap (`1177`) e impersonations (`1178/1179/1180/1181`) + `p2-smoke` (`1175`) **revogados**; usuário de teste **C2 (1121)** e vínculo removidos; **7 `.secret` locais triturados** + ids temporários removidos. Tokens de sessões anteriores (`830/835/1126`) **intocados**.

---

## 7. Persistência e ausência de código-fonte no Postgres

- `source_doc_quality_analyses` (metadados da execução) + `source_doc_quality_findings` (metadados do achado: `rule/severity/category/title/description/recommendation/file/line/occurrences`) — **nenhum `snippet`/código**. `persistFindings` remove chaves reveladoras de código antes de gravar.
- Durabilidade P2 comprovada: análise **id 26** com **118 findings persistidos** servidos do Postgres (autoridade), independentes do CA. Snippets, quando exibidos a quem tem `view_git`, são **enriquecidos on-demand** a partir do git — não persistidos.

---

## 8. Concorrência

- **Serialização:** `Semaphore(1)` garante 1 análise por vez no CA (`-w1` sozinho não bastava para threads daemon).
- **Dedup inflight:** índice único parcial (1 in-flight por `doc,blob`) + checagens de reuse/inflight no `run()`; duplo-clique devolve a análise em andamento (202), não duplica.
- **C7 PASS:** 2 fontes distintas serializam corretas; mesma fonte 2× → 1 inflight.

---

## 9. Timeouts e indisponibilidade

- `CODEANALYSIS_TIMEOUT=900` (BFF→CA). Estouro → `failed` com `error_code` claro; **sem 500 cru**; prontuário intacto (C5).
- CA indisponível/caindo → `job_lost` com reconciliação guardada; 503/failed controlado; sem "fantasma" completed; recuperação limpa ao voltar (C6).
- **Ressalva LIVE:** observou-se `job_lost` **intermitente** (CA tinha o job; `getJob` do BFF retornou `null` transitório). **Não reproduzido**; mitigado pela reconciliação guardada; instrumentação temporária foi inconclusiva (logs do Laravel não chegam ao stream do Render) e **já revertida**.

---

## 10. Estado final da infraestrutura

| Componente | Estado |
|---|---|
| **CA** `minutor-codeanalysis-homolog` (`srv-da6tve0u01pc73dpgas0`, private) | Live · plano **`standard` (2 GB)** · `CA_ANALYZER_RUNNER=inprocess` · `CA_ANALYZER_TIMEOUT` **ausente** (default 900) · não suspenso |
| **BE** `minutor-backend-homolog` (`srv-d7jlu2d8nd3s73abm7h0`) | Live commit **`a849b66a`** (revert da instrumentação = código definitivo) · `CODEANALYSIS_ENABLED=true` · `CODEANALYSIS_TIMEOUT=900` · `BASE_URL=http://minutor-codeanalysis-homolog:8080` · `/up` 200 |
| **FE** `minutor-frontend-homolog` (`srv-d7jm162qqhas738htqk0`) | Live commit **`0ebdaaa6`** (R1) |
| **DB** | Supabase `puwqaoefmfxhqjdukqru` |
| **fonte 8** `CCSPCP02.PRW` | `current_version_id=19`, blob `c0158b0d5f311eac…` (= Git HEAD original), última análise id 26 `completed` (ATUAL) |

CA e BE **healthy**. Nenhuma instrumentação temporária remanescente. Sessões/tokens temporários do L1.2 **revogados**.

---

## 11. Riscos técnicos residuais

1. **CA-R1a (principal):** analyzer in-process acoplado à liveness. Sob carga real (fontes grandes, concorrência, CPU) o modelo de container único pode voltar a travar o health check. Mitigado, não resolvido.
2. **`job_lost` intermitente:** causa-raiz não isolada (instrumentação inconclusiva); coberto pela reconciliação guardada, mas convém observar em volume.
3. **R2 não corrigido:** `disabled` ainda cria linha `failed/disabled` (auditado; fix mínimo pronto, sem aval de implementar).
4. **`service_enabled` código morto** no FE (BFF não emite a flag).
5. **Plano 2 GB é medida de investigação**, não sizing permanente (§13).

---

## 12. Itens a resolver antes de produção

1. **Definir o modelo de capacidade de produção pelo comportamento do analyzer** (CPU, health check, concorrência, timeout, tamanho das fontes) — **não** tratar "aumentar RAM" como solução de capacidade.
2. **Resolver CA-R1a arquiteturalmente:** desacoplar a execução do analyzer da liveness HTTP (ex.: worker/execução out-of-process com liveness própria), para que uma análise pesada não derrube o container.
3. **Isolar a causa-raiz do `job_lost` intermitente** (ou aceitar formalmente a reconciliação guardada como suficiente, com observabilidade adequada — logs do analyzer/BFF que efetivamente cheguem ao stream).
4. **R2:** aplicar a guarda antecipada de `disabled` (e decidir sobre emitir `service_enabled`) se estados desabilitados forem esperados em produção.
5. **Observabilidade:** garantir que logs relevantes do BFF/CA cheguem ao coletor (hoje `LOG_CHANNEL=stderr` do Laravel não chega ao stream do Render via php-fpm).

---

## 13. Recomendação — CA de 2 GB → Starter após esta bateria

Os 2 GB serviram para **eliminar memória como variável** durante a investigação. Como o gargalo real era o **health check agressivo** (não OOM) e a análise pesada teve **pico ~335 MiB**, e como homologação terá apenas **testes pontuais**:

- **Recomendação:** encerrada esta bateria, **voltar o CA para Starter (512 MB) e observar**. Manter 2 GB **por enquanto**, conforme combinado.
- **Conclusão arquitetural (mais relevante que custo):** o dimensionamento de **produção** deve ser guiado pelo comportamento do analyzer sob CPU/health-check/concorrência/timeout/tamanho das fontes — o L1.2 já produziu dados objetivos para começar essa decisão. "Mais RAM" não é a estratégia.

---

## Encerramento

L1.2 concluído: **8/8 PASS**, **R1 corrigido e validado sem F5**, **R2 auditado** (proposta + diff mínimo, sem implementar), **correções arquiteturais** (P2/P3/job_lost/CA-R1b/health check) entregues, **infra final saudável e limpa**. **PARADA aqui — não iniciar L2/L3.**
